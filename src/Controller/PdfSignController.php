<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\PdfSigner;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;

final class PdfSignController extends AbstractController
{
    private const SESSION_KEY = 'uploaded_pdf';
    private const MAX_UPLOAD_BYTES = 15_000_000;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        private readonly string $projectDir,
        private readonly PdfSigner $pdfSigner,
        private readonly Filesystem $filesystem,
    ) {
    }

    #[Route('/', name: 'app_pdf_sign_index', methods: ['GET'])]
    public function index(SessionInterface $session): Response
    {
        /** @var array{path: string, originalName: string}|null $uploadedPdf */
        $uploadedPdf = $session->get(self::SESSION_KEY);

        return $this->render('pdf_sign/index.html.twig', [
            'hasUploadedPdf' => $uploadedPdf !== null && is_file($uploadedPdf['path']),
            'originalName' => $uploadedPdf['originalName'] ?? null,
        ]);
    }

    #[Route('/upload', name: 'app_pdf_sign_upload', methods: ['POST'])]
    public function upload(Request $request, SessionInterface $session): Response
    {
        $uploadedFile = $request->files->get('pdfFile');

        if (!$uploadedFile instanceof UploadedFile) {
            $this->addFlash('error', 'Merci de choisir un fichier PDF.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        if ($uploadedFile->getSize() !== null && $uploadedFile->getSize() > self::MAX_UPLOAD_BYTES) {
            $this->addFlash('error', 'Le fichier depasse la taille maximale de 15 Mo.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        $mimeType = $uploadedFile->getMimeType();
        if ($mimeType !== 'application/pdf' && strtolower((string) $uploadedFile->guessExtension()) !== 'pdf') {
            $this->addFlash('error', 'Seuls les fichiers PDF sont acceptes.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        $existing = $session->get(self::SESSION_KEY);
        if (is_array($existing) && isset($existing['path']) && is_string($existing['path']) && is_file($existing['path'])) {
            $this->filesystem->remove($existing['path']);
        }

        $storageDirectory = $this->projectDir . '/var/storage/pdfs';
        $this->filesystem->mkdir($storageDirectory);

        $filename = sprintf('%s.pdf', bin2hex(random_bytes(16)));

        try {
            $uploadedFile->move($storageDirectory, $filename);
        } catch (FileException) {
            $this->addFlash('error', 'Impossible denregistrer le fichier.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        $session->set(self::SESSION_KEY, [
            'path' => $storageDirectory . '/' . $filename,
            'originalName' => $uploadedFile->getClientOriginalName(),
        ]);

        $this->addFlash('success', 'PDF importe. Tu peux maintenant le signer.');

        return $this->redirectToRoute('app_pdf_sign_index');
    }

    #[Route('/preview', name: 'app_pdf_sign_preview', methods: ['GET'])]
    public function preview(SessionInterface $session): Response
    {
        $uploadedPdf = $session->get(self::SESSION_KEY);

        if (!is_array($uploadedPdf) || !isset($uploadedPdf['path']) || !is_string($uploadedPdf['path']) || !is_file($uploadedPdf['path'])) {
            throw $this->createNotFoundException('Aucun fichier PDF importe.');
        }

        $response = new BinaryFileResponse($uploadedPdf['path']);
        $response->headers->set('Content-Type', 'application/pdf');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_INLINE, $uploadedPdf['originalName'] ?? 'document.pdf');

        return $response;
    }

    #[Route('/sign', name: 'app_pdf_sign_sign', methods: ['POST'])]
    public function sign(Request $request, SessionInterface $session): Response
    {
        $uploadedPdf = $session->get(self::SESSION_KEY);

        if (!is_array($uploadedPdf) || !isset($uploadedPdf['path']) || !is_string($uploadedPdf['path']) || !is_file($uploadedPdf['path'])) {
            $this->addFlash('error', 'Importe un PDF avant de le signer.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        $signaturesData = (string) $request->request->get('signaturesData', '');

        if ($signaturesData === '') {
            $this->addFlash('error', 'Ajoute au moins une signature avant de continuer.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        try {
            $decodedSignatures = json_decode($signaturesData, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decodedSignatures) || $decodedSignatures === []) {
                $this->addFlash('error', 'Ajoute au moins une signature avant de continuer.');

                return $this->redirectToRoute('app_pdf_sign_index');
            }

            /** @var array<int, array<string, mixed>> $decodedSignatures */
            $signedPath = $this->pdfSigner->sign($uploadedPdf['path'], $decodedSignatures);
        } catch (\Throwable) {
            $this->addFlash('error', 'Impossible de signer ce PDF. Verifie la signature et reessaie.');

            return $this->redirectToRoute('app_pdf_sign_index');
        }

        $downloadName = sprintf(
            '%s-signed.pdf',
            pathinfo((string) ($uploadedPdf['originalName'] ?? 'document'), PATHINFO_FILENAME),
        );

        $response = new BinaryFileResponse($signedPath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $downloadName);
        $response->deleteFileAfterSend(true);

        return $response;
    }

    #[Route('/reset', name: 'app_pdf_sign_reset', methods: ['POST'])]
    public function reset(SessionInterface $session): Response
    {
        $uploadedPdf = $session->get(self::SESSION_KEY);

        if (is_array($uploadedPdf) && isset($uploadedPdf['path']) && is_string($uploadedPdf['path']) && is_file($uploadedPdf['path'])) {
            $this->filesystem->remove($uploadedPdf['path']);
        }

        $session->remove(self::SESSION_KEY);
        $this->addFlash('success', 'Le document courant a ete retire.');

        return $this->redirectToRoute('app_pdf_sign_index');
    }
}
