#!/usr/bin/env python3
"""Incremental PAdES signing / stamping helper built on pyHanko.

Reads a JSON job from stdin and writes the result PDF. The original PDF bytes
are never rewritten: every change is appended as an incremental update.
"""
import json
import struct
import sys
import uuid
import zlib
from io import BytesIO

from pyhanko.pdf_utils import generic, layout
from pyhanko.pdf_utils.content import PdfContent, ResourceType
from pyhanko.pdf_utils.generic import pdf_name
from pyhanko.pdf_utils.incremental_writer import IncrementalPdfFileWriter
from pyhanko.pdf_utils.reader import PdfFileReader
from pyhanko.pdf_utils.writer import copy_into_new_writer
from pyhanko.sign import fields, signers, timestamps
from pyhanko.stamp import StaticContentStamp, StaticStampStyle, TextStampStyle


def _paeth(a, b, c):
    p = a + b - c
    pa = abs(p - a)
    pb = abs(p - b)
    pc = abs(p - c)
    if pa <= pb and pa <= pc:
        return a
    if pb <= pc:
        return b
    return c


def _unfilter_png_rows(raw, width, height, bpp):
    stride = width * bpp
    expected = height * (stride + 1)
    if len(raw) < expected:
        raise ValueError('Truncated PNG IDAT stream')
    out = bytearray(height * stride)
    prev = bytearray(stride)
    pos = 0
    for y in range(height):
        ftype = raw[pos]
        pos += 1
        row = bytearray(raw[pos:pos + stride])
        pos += stride
        if ftype == 0:
            pass
        elif ftype == 1:
            for i in range(bpp, stride):
                row[i] = (row[i] + row[i - bpp]) & 0xFF
        elif ftype == 2:
            for i in range(stride):
                row[i] = (row[i] + prev[i]) & 0xFF
        elif ftype == 3:
            for i in range(stride):
                left = row[i - bpp] if i >= bpp else 0
                row[i] = (row[i] + ((left + prev[i]) >> 1)) & 0xFF
        elif ftype == 4:
            for i in range(stride):
                left = row[i - bpp] if i >= bpp else 0
                up = prev[i]
                up_left = prev[i - bpp] if i >= bpp else 0
                row[i] = (row[i] + _paeth(left, up, up_left)) & 0xFF
        else:
            raise ValueError(f'Unsupported PNG filter: {ftype}')
        out[y * stride:(y + 1) * stride] = row
        prev = row
    return bytes(out)


def _read_png_dimensions(data):
    if len(data) < 29 or data[:8] != b'\x89PNG\r\n\x1a\n' or data[12:16] != b'IHDR':
        raise ValueError('Invalid PNG header')
    width, height = struct.unpack('>II', data[16:24])
    return width, height


def _parse_png_to_xobject(data, writer):
    pos = 8
    width = height = bit_depth = color_type = interlace = 0
    plte = b''
    trns = b''
    idat = bytearray()
    while pos + 8 <= len(data):
        length = struct.unpack('>I', data[pos:pos + 4])[0]
        ctype = data[pos + 4:pos + 8]
        chunk = data[pos + 8:pos + 8 + length]
        pos += 12 + length
        if ctype == b'IHDR':
            width, height, bit_depth, color_type, comp, flt, interlace = struct.unpack('>IIBBBBB', chunk)
            if comp != 0 or flt != 0:
                raise ValueError('Unsupported PNG compression/filter method')
        elif ctype == b'PLTE':
            plte = chunk
        elif ctype == b'tRNS':
            trns = chunk
        elif ctype == b'IDAT':
            idat.extend(chunk)
        elif ctype == b'IEND':
            break

    if interlace != 0:
        raise ValueError('Interlaced PNG requires Pillow')
    if bit_depth != 8:
        raise ValueError(f'PNG bit depth {bit_depth} requires Pillow')

    channels_map = {0: 1, 2: 3, 3: 1, 4: 2, 6: 4}
    if color_type not in channels_map:
        raise ValueError(f'Unsupported PNG color type {color_type}')
    bpp = channels_map[color_type]
    pixels = _unfilter_png_rows(zlib.decompress(bytes(idat)), width, height, bpp)

    alpha_bytes = None
    if color_type == 0:
        color_space = pdf_name('/DeviceGray')
        rgb_bytes = pixels
    elif color_type == 2:
        color_space = pdf_name('/DeviceRGB')
        rgb_bytes = pixels
    elif color_type == 3:
        if not plte:
            raise ValueError('Missing PLTE chunk')
        color_space = pdf_name('/DeviceRGB')
        rgb = bytearray(width * height * 3)
        alpha = bytearray(width * height) if trns else None
        for idx, pval in enumerate(pixels):
            off = pval * 3
            rgb[idx * 3:idx * 3 + 3] = plte[off:off + 3]
            if alpha is not None:
                alpha[idx] = trns[pval] if pval < len(trns) else 255
        rgb_bytes = bytes(rgb)
        alpha_bytes = bytes(alpha) if alpha is not None else None
    elif color_type in (4, 6):
        is_gray = (color_type == 4)
        color_space = pdf_name('/DeviceGray' if is_gray else '/DeviceRGB')
        c_count = 1 if is_gray else 3
        rgb = bytearray(width * height * c_count)
        alpha = bytearray(width * height)
        for idx in range(width * height):
            src = idx * bpp
            rgb[idx * c_count:(idx + 1) * c_count] = pixels[src:src + c_count]
            alpha[idx] = pixels[src + c_count]
        rgb_bytes = bytes(rgb)
        alpha_bytes = bytes(alpha)

    dict_data = {
        pdf_name('/Type'): pdf_name('/XObject'),
        pdf_name('/Subtype'): pdf_name('/Image'),
        pdf_name('/Width'): generic.NumberObject(width),
        pdf_name('/Height'): generic.NumberObject(height),
        pdf_name('/ColorSpace'): color_space,
        pdf_name('/BitsPerComponent'): generic.NumberObject(8),
    }

    if alpha_bytes is not None:
        smask = generic.StreamObject({
            pdf_name('/Type'): pdf_name('/XObject'),
            pdf_name('/Subtype'): pdf_name('/Image'),
            pdf_name('/Width'): generic.NumberObject(width),
            pdf_name('/Height'): generic.NumberObject(height),
            pdf_name('/ColorSpace'): pdf_name('/DeviceGray'),
            pdf_name('/BitsPerComponent'): generic.NumberObject(8),
        }, stream_data=alpha_bytes)
        smask.compress()
        dict_data[pdf_name('/SMask')] = writer.add_object(smask)

    stream = generic.StreamObject(dict_data, stream_data=rgb_bytes)
    stream.compress()
    return writer.add_object(stream)


def _read_jpeg_header(data):
    if data[:2] != b'\xff\xd8':
        raise ValueError('Not a JPEG file')
    pos = 2
    width = height = components = 0
    while pos + 4 <= len(data):
        if data[pos] != 0xFF:
            pos += 1
            continue
        while pos < len(data) and data[pos] == 0xFF:
            pos += 1
        if pos >= len(data):
            break
        marker = data[pos]
        pos += 1
        if marker in (0xD8, 0xD9, 0x01) or (0xD0 <= marker <= 0xD7):
            continue
        if pos + 2 > len(data):
            break
        seg_len = struct.unpack('>H', data[pos:pos + 2])[0]
        if marker in (0xC0, 0xC1, 0xC2, 0xC3, 0xC5, 0xC6, 0xC7, 0xC9, 0xCA, 0xCB, 0xCD, 0xCE, 0xCF):
            if pos + 7 <= len(data):
                _, height, width, components = struct.unpack('>BHHB', data[pos + 2:pos + 8])
            break
        pos += seg_len

    if width <= 0 or height <= 0 or components not in (1, 3, 4):
        raise ValueError('Unsupported JPEG header')
    return width, height, components


def _parse_jpeg_to_xobject(data, writer):
    width, height, components = _read_jpeg_header(data)
    cs_map = {1: '/DeviceGray', 3: '/DeviceRGB', 4: '/DeviceCMYK'}
    dict_data = {
        pdf_name('/Type'): pdf_name('/XObject'),
        pdf_name('/Subtype'): pdf_name('/Image'),
        pdf_name('/Width'): generic.NumberObject(width),
        pdf_name('/Height'): generic.NumberObject(height),
        pdf_name('/ColorSpace'): pdf_name(cs_map[components]),
        pdf_name('/BitsPerComponent'): generic.NumberObject(8),
        pdf_name('/Filter'): pdf_name('/DCTDecode'),
    }
    if components == 4:
        dict_data[pdf_name('/Decode')] = generic.ArrayObject([
            generic.NumberObject(1), generic.NumberObject(0),
            generic.NumberObject(1), generic.NumberObject(0),
            generic.NumberObject(1), generic.NumberObject(0),
            generic.NumberObject(1), generic.NumberObject(0),
        ])
    stream = generic.StreamObject(dict_data, encoded_data=data)
    return writer.add_object(stream)


class StdlibPdfImage(PdfContent):
    """Pure-stdlib PDF image XObject wrapper for environments without Pillow."""

    def __init__(self, image_path, writer=None, box=None):
        with open(image_path, 'rb') as handle:
            self._data = handle.read()
        if self._data[:8] == b'\x89PNG\r\n\x1a\n':
            self._fmt = 'png'
            w, h = _read_png_dimensions(self._data)
        elif self._data[:2] == b'\xff\xd8':
            self._fmt = 'jpeg'
            w, h, _ = _read_jpeg_header(self._data)
        else:
            raise ValueError('Unsupported image format without Pillow')
        self.name = str(uuid.uuid4())
        self._image_ref = None
        if box is None:
            box = layout.BoxConstraints(w, h)
        super().__init__(writer=writer, box=box)

    @property
    def image_ref(self):
        assert self.writer is not None
        if self._image_ref is None or self._image_ref.get_pdf_handler() is not self.writer:
            if self._fmt == 'png':
                self._image_ref = _parse_png_to_xobject(self._data, self.writer)
            else:
                self._image_ref = _parse_jpeg_to_xobject(self._data, self.writer)
        return self._image_ref

    def render(self):
        img_ref_name = '/Img' + self.name
        self.set_resource(category=ResourceType.XOBJECT, name=pdf_name(img_ref_name), value=self.image_ref)
        draw = b'%g 0 0 %g 0 0 cm %s Do' % (self.box.width, self.box.height, img_ref_name.encode('ascii'))
        return b'q %s Q' % draw


def load_stamp_image(path):
    """Load an image, normalize EXIF orientation and convert unsupported Pillow modes."""
    from PIL import Image, ImageOps

    img = Image.open(path)
    img.load()
    img = ImageOps.exif_transpose(img)
    if img.mode == 'P':
        if 'transparency' in img.info or (img.palette and img.palette.mode != 'RGB'):
            img = img.convert('RGBA')
    elif img.mode not in ('RGB', 'RGBA', 'L', 'LA'):
        has_alpha = 'A' in img.getbands() or 'transparency' in img.info
        img = img.convert('RGBA' if has_alpha else 'RGB')
    return img


def create_stamp_background(path):
    try:
        from pyhanko.pdf_utils.images import PdfImage

        return PdfImage(load_stamp_image(path))
    except ImportError:
        return StdlibPdfImage(path)


def decompress_pdf(data):
    """Rewrite a PDF 1.5+ (XRefStream / ObjStm) without compressed xref/object streams for FPDI."""
    reader = PdfFileReader(BytesIO(data))
    writer = copy_into_new_writer(reader, {'stream_xrefs': False})
    out = BytesIO()
    writer.write(out)
    return out.getvalue()


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
            background=create_stamp_background(stamp['path']),
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
    raw_page = digital.get('page')
    page_num = int(raw_page) if raw_page is not None else page_count
    index = min(max(page_num - 1, 0), page_count - 1)
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

    if job.get('action') == 'decompress':
        data = decompress_pdf(data)
    else:
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
