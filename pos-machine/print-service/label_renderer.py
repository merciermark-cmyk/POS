"""
Label renderer for Epson TM-L90 Plus LFC (58mm linerless, 203 DPI).

Renders product labels as PIL Images:
  - Product name (large, bold)
  - Brewing instructions
  - PLU code
  - Website URL

Printable width: ~48mm = 384 dots at 203 DPI.
Height: auto-sized to content.
"""

from PIL import Image, ImageDraw, ImageFont
import textwrap

LABEL_WIDTH = 384   # dots (58mm at 203 DPI, ~48mm printable)
MARGIN = 12         # dots padding on each side
TEXT_WIDTH = LABEL_WIDTH - 2 * MARGIN

# Font sizes (in pixels at 203 DPI)
FONT_SIZE_NAME = 30
FONT_SIZE_BREW = 20
FONT_SIZE_PLU = 24
FONT_SIZE_URL = 16

# Approximate chars per line for word wrapping
CHARS_NAME = 18
CHARS_BREW = 28
CHARS_PLU = 22
CHARS_URL = 34


def _load_font(size: int, bold: bool = False) -> ImageFont.FreeTypeFont:
    """Load a TrueType font, falling back to default if unavailable."""
    # Try common Windows fonts
    names = []
    if bold:
        names = ['arialbd.ttf', 'calibrib.ttf', 'verdanab.ttf']
    else:
        names = ['arial.ttf', 'calibri.ttf', 'verdana.ttf']

    for name in names:
        try:
            return ImageFont.truetype(name, size)
        except (OSError, IOError):
            continue

    # Fallback to default
    try:
        return ImageFont.truetype('arial.ttf', size)
    except (OSError, IOError):
        return ImageFont.load_default()


def _draw_bold(draw: ImageDraw.ImageDraw, xy: tuple, text: str,
               font: ImageFont.FreeTypeFont, fill: int = 0):
    """Draw text with 2x2 pixel offset for darker thermal output."""
    x, y = xy
    for dx in range(2):
        for dy in range(2):
            draw.text((x + dx, y + dy), text, font=font, fill=fill)


def render_label(product_name: str,
                 brewing_instructions: str = '',
                 product_code: str = '',
                 url: str = 'www.granvilletea.com') -> Image.Image:
    """
    Render a product label as a PIL Image.

    Returns a 1-bit (mode '1') image suitable for ESC/POS raster printing.
    Width is fixed at LABEL_WIDTH; height is auto-sized to content.
    """
    font_name = _load_font(FONT_SIZE_NAME, bold=True)
    font_brew = _load_font(FONT_SIZE_BREW)
    font_plu = _load_font(FONT_SIZE_PLU, bold=True)
    font_url = _load_font(FONT_SIZE_URL)

    # Pre-calculate text blocks
    name_lines = textwrap.wrap(product_name, width=CHARS_NAME) or ['']
    brew_lines = textwrap.wrap(brewing_instructions, width=CHARS_BREW) if brewing_instructions else []
    code_text = f'PLU: {product_code}' if product_code else ''

    # Calculate height
    line_spacing = 4
    y = MARGIN

    # Product name
    name_height = len(name_lines) * (FONT_SIZE_NAME + line_spacing)
    y += name_height + 8

    # Brewing instructions
    if brew_lines:
        brew_height = len(brew_lines) * (FONT_SIZE_BREW + line_spacing)
        y += brew_height + 6

    # PLU code
    if code_text:
        y += FONT_SIZE_PLU + line_spacing + 2

    # URL
    if url:
        y += FONT_SIZE_URL + line_spacing + 2

    y += MARGIN  # bottom margin

    # Create image (white background)
    img = Image.new('1', (LABEL_WIDTH, y), 1)  # 1 = white in mode '1'
    draw = ImageDraw.Draw(img)

    # Draw content
    cy = MARGIN

    # Product name (centered, bold)
    for line in name_lines:
        bbox = draw.textbbox((0, 0), line, font=font_name)
        tw = bbox[2] - bbox[0]
        x = (LABEL_WIDTH - tw) // 2
        _draw_bold(draw, (x, cy), line, font=font_name)
        cy += FONT_SIZE_NAME + line_spacing
    cy += 8

    # Divider line
    draw.line([(MARGIN, cy - 4), (LABEL_WIDTH - MARGIN, cy - 4)], fill=0, width=1)

    # Brewing instructions (centered)
    if brew_lines:
        for line in brew_lines:
            bbox = draw.textbbox((0, 0), line, font=font_brew)
            tw = bbox[2] - bbox[0]
            x = (LABEL_WIDTH - tw) // 2
            _draw_bold(draw, (x, cy), line, font=font_brew)
            cy += FONT_SIZE_BREW + line_spacing
        cy += 6

    # PLU code (centered)
    if code_text:
        bbox = draw.textbbox((0, 0), code_text, font=font_plu)
        tw = bbox[2] - bbox[0]
        x = (LABEL_WIDTH - tw) // 2
        _draw_bold(draw, (x, cy), code_text, font=font_plu)
        cy += FONT_SIZE_PLU + line_spacing + 2

    # URL (centered)
    if url:
        bbox = draw.textbbox((0, 0), url, font=font_url)
        tw = bbox[2] - bbox[0]
        x = (LABEL_WIDTH - tw) // 2
        _draw_bold(draw, (x, cy), url, font=font_url)

    return img


def image_to_raster_bytes(img: Image.Image) -> bytes:
    """
    Convert a 1-bit PIL Image to ESC/POS raster data for GS v 0 command.

    The TM-L90 uses: GS v 0 m xL xH yL yH d1...dk
    where m=0 (normal), xL/xH = bytes per line, yL/yH = number of lines.
    Each byte represents 8 horizontal dots (MSB = leftmost).
    """
    # Ensure mode '1' (1-bit pixels)
    if img.mode != '1':
        img = img.convert('1')

    width, height = img.size
    pixels = img.load()

    # Bytes per row (each byte = 8 dots)
    bytes_per_row = (width + 7) // 8

    # Build raster data
    raster = bytearray()
    for y_pos in range(height):
        for x_byte in range(bytes_per_row):
            byte_val = 0
            for bit in range(8):
                x_pos = x_byte * 8 + bit
                if x_pos < width:
                    # In mode '1': 0 = black, 255 = white
                    # ESC/POS raster: 1 = print (black), 0 = no print (white)
                    pixel = pixels[x_pos, y_pos]
                    if pixel == 0:  # black pixel
                        byte_val |= (0x80 >> bit)
            raster.append(byte_val)

    # GS v 0 command
    # m = 0 (normal density)
    GS = b'\x1d'
    cmd = GS + b'v0'
    cmd += bytes([0])  # m = normal
    cmd += bytes([bytes_per_row & 0xFF, (bytes_per_row >> 8) & 0xFF])  # xL, xH
    cmd += bytes([height & 0xFF, (height >> 8) & 0xFF])  # yL, yH
    cmd += bytes(raster)

    return cmd
