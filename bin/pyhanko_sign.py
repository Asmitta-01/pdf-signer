#!/usr/bin/env python3
"""Incremental PAdES signing / stamping helper built on pyHanko.

Reads a JSON job from stdin and writes the result PDF. The original PDF bytes
are never rewritten: every change is appended as an incremental update.
"""
import json
import sys
from io import BytesIO

from pyhanko.pdf_utils import layout
from pyhanko.pdf_utils.images import PdfImage
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.sign import fields, signers, timestamps
from pyhanko.stamp import StaticContentStamp, StaticStampStyle, TextStampStyle


def page_size(writer, index):
    page_ref, _ = writer.find_page_for_modification(index)
    node = page_ref.get_object()
    while node is not None:
        if '/MediaBox' in node:
            box = [float(v) for v in node['/MediaBox']]
            return box[2] - box[0], box[3] - box[1], box[0], box[1]
        parent = node.get('/Parent')
        node = parent.get_object() if parent is not None else None
    return 595.0, 842.0, 0.0, 0.0


def to_box(writer, index, x_ratio, y_ratio, w, h):
    pw, ph, ox, oy = page_size(writer, index)
    x = ox + pw * x_ratio
    y = oy + ph - ph * y_ratio - h
    x = min(max(x, ox), ox + max(pw - w, 0))
    y = min(max(y, oy), oy + max(ph - h, 0))
    return int(round(x)), int(round(y)), int(round(w)), int(round(h))


def apply_stamps(data, stamps):
    writer = IncrementalPdfFileWriter(BytesIO(data))
    page_count = int(writer.root['/Pages']['/Count'])
    for stamp in stamps:
        index = min(max(int(stamp['page']) - 1, 0), page_count - 1)
        pw, _, _, _ = page_size(writer, index)
        width = max(20.0, pw * float(stamp['widthRatio']))
        height = width * float(stamp['aspectRatio'])
        x, y, w, h = to_box(writer, index, float(stamp['xRatio']), float(stamp['yRatio']), width, height)
        style = StaticStampStyle(
            border_width=0,
            background=PdfImage(stamp['path']),
            background_layout=layout.SimpleBoxLayoutRule(
                x_align=layout.AxisAlignment.ALIGN_MID,
                y_align=layout.AxisAlignment.ALIGN_MID,
                margins=layout.Margins.uniform(0),
                inner_content_scaling=layout.InnerScaling.STRETCH_FILL,
            ),
        )
        StaticContentStamp(writer, style, layout.BoxConstraints(w, h)).apply(index, x, y)
    out = BytesIO()
    writer.write(out)
    return out.getvalue()


def sign(data, digital, p12_path, p12_password, tsa_url):
    signer = signers.SimpleSigner.load_pkcs12(
        p12_path, passphrase=p12_password.encode() if p12_password else None
    )
    if signer is None:
        raise RuntimeError('Impossible de charger le certificat PKCS#12.')

    writer = IncrementalPdfFileWriter(BytesIO(data))
    page_count = int(writer.root['/Pages']['/Count'])
    index = min(max(int(digital['page']) - 1, 0), page_count - 1)
    pw, _, _, _ = page_size(writer, index)
    width = max(60.0, pw * float(digital['widthRatio']))
    height = max(24.0, width * 0.28)
    x, y, w, h = to_box(writer, index, float(digital['xRatio']), float(digital['yRatio']), width, height)

    fields.append_signature_field(
        writer,
        fields.SigFieldSpec('Signature1', on_page=index, box=(x, y, x + w, y + h)),
    )

    meta = signers.PdfSignatureMetadata(
        field_name='Signature1',
        subfilter=fields.SigSeedSubFilter.PADES,
        md_algorithm='sha256',
        reason=digital.get('reason') or None,
        name=digital['name'],
    )
    timestamper = timestamps.HTTPTimeStamper(tsa_url) if tsa_url else None
    pdf_signer = signers.PdfSigner(
        meta,
        signer=signer,
        timestamper=timestamper,
        stamp_style=TextStampStyle(
            stamp_text='Signé numériquement par %(signer)s\nLe %(ts)s',
            border_width=1,
        ),
    )
    out = BytesIO()
    pdf_signer.sign_pdf(writer, output=out, appearance_text_params={'signer': digital['name']})
    return out.getvalue()


def main():
    job = json.load(sys.stdin)
    with open(job['input'], 'rb') as handle:
        data = handle.read()

    if job.get('stamps'):
        data = apply_stamps(data, job['stamps'])
    if job.get('digital'):
        data = sign(data, job['digital'], job['p12'], job.get('p12Password', ''), job.get('tsaUrl'))

    with open(job['output'], 'wb') as handle:
        handle.write(data)


if __name__ == '__main__':
    try:
        main()
    except Exception as exc:  # reported to the PHP caller through stderr
        print(f'{type(exc).__name__}: {exc}', file=sys.stderr)
        sys.exit(1)
