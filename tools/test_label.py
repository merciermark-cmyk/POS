"""
Test product label on Epson TM-L90 Plus (58mm linerless, serial, 203 DPI).
Renders with Pillow, sends as ESC/POS raster image.
"""
import serial
import time
from PIL import Image, ImageDraw, ImageFont
import struct

PORT = 'COM3'
BAUD = 19200

# 58mm paper: ~384 printable dots wide at 203 DPI
# 1.5 inches tall = 304 dots at 203 DPI
LABEL_W = 384
LABEL_H = 120  # ~0.6 inches (cut + feed adds ~0.4")

# Sample product data
product_name = "Earl Grey Cream"
brewing_tips = "1 tsp/cup, 200°F, steep 3-4 min"
plu = "1042"

# --- Render label ---
try:
    font_name = ImageFont.truetype("arialbd.ttf", 30)
    font_tips = ImageFont.truetype("arialbd.ttf", 20)
    font_plu = ImageFont.truetype("arialbd.ttf", 24)
except:
    font_name = ImageFont.load_default()
    font_tips = font_name
    font_plu = font_name

def draw_bold(draw, pos, text, font):
    """Draw text with 1px offsets to thicken strokes for thermal printing."""
    x, y = pos
    for dx in range(2):
        for dy in range(2):
            draw.text((x + dx, y + dy), text, font=font, fill=0)

# First pass: measure total content height
tmp = Image.new('1', (LABEL_W, 300), 1)
tmp_draw = ImageDraw.Draw(tmp)
y = 0

name_bbox = tmp_draw.textbbox((0, 0), product_name, font=font_name)
name_h = name_bbox[3] - name_bbox[1]
y += name_h + 6 + 2 + 5  # name + gap + divider + gap

tips_bbox = tmp_draw.textbbox((0, 0), brewing_tips, font=font_tips)
tips_w = tips_bbox[2] - tips_bbox[0]
if tips_w > LABEL_W - 20:
    words = brewing_tips.split(', ')
    mid = len(words) // 2
    line1_h = tmp_draw.textbbox((0,0), ', '.join(words[:mid]), font=font_tips)
    line2_h = tmp_draw.textbbox((0,0), ', '.join(words[mid:]), font=font_tips)
    tips_total = (line1_h[3]-line1_h[1]) + 2 + (line2_h[3]-line2_h[1])
else:
    tips_total = tips_bbox[3] - tips_bbox[1]
y += tips_total + 3 + 2  # tips + gap

plu_text = f"PLU: {plu}"
plu_bbox = tmp_draw.textbbox((0, 0), plu_text, font=font_plu)
plu_h = plu_bbox[3] - plu_bbox[1]
y += plu_h

content_height = y
top_margin = 2  # minimal — feed/cut adds space at top

# Second pass: draw centered vertically
img = Image.new('1', (LABEL_W, LABEL_H), 1)
draw = ImageDraw.Draw(img)
y = top_margin

# Product name
tw = name_bbox[2] - name_bbox[0]
draw_bold(draw, ((LABEL_W - tw) // 2, y), product_name, font_name)
y += name_h + 6

# Divider
draw.line([(10, y), (LABEL_W - 10, y)], fill=0, width=2)
y += 5

# Brewing tips
if tips_w > LABEL_W - 20:
    words = brewing_tips.split(', ')
    mid = len(words) // 2
    for line in [', '.join(words[:mid]), ', '.join(words[mid:])]:
        bbox = draw.textbbox((0, 0), line, font=font_tips)
        tw = bbox[2] - bbox[0]
        draw_bold(draw, ((LABEL_W - tw) // 2, y), line, font_tips)
        y += bbox[3] - bbox[1] + 2
else:
    tw = tips_bbox[2] - tips_bbox[0]
    draw_bold(draw, ((LABEL_W - tw) // 2, y), brewing_tips, font_tips)
    y += tips_total + 3

y += 2

# PLU
tw = plu_bbox[2] - plu_bbox[0]
draw_bold(draw, ((LABEL_W - tw) // 2, y), plu_text, font_plu)
y += plu_h + 4

# Website
url = "www.granvilletea.com"
try:
    font_url = ImageFont.truetype("arial.ttf", 16)
except:
    font_url = font_tips
url_bbox = draw.textbbox((0, 0), url, font=font_url)
tw = url_bbox[2] - url_bbox[0]
draw_bold(draw, ((LABEL_W - tw) // 2, y), url, font_url)

# Save preview
img.save(r'C:\Users\mark\pos\tools\label_preview.png')
print("Preview saved to label_preview.png")

# --- Convert to ESC/POS raster ---
def image_to_raster(img):
    """Convert 1-bit PIL image to ESC/POS raster bytes."""
    w, h = img.size
    # Width in bytes (8 pixels per byte)
    wb = (w + 7) // 8
    pixels = img.load()
    data = bytearray()
    for row in range(h):
        for col_byte in range(wb):
            byte = 0
            for bit in range(8):
                px = col_byte * 8 + bit
                if px < w and pixels[px, row] == 0:  # black pixel
                    byte |= (0x80 >> bit)
            data.append(byte)
    return bytes(data), wb, h

raster_data, wb, h = image_to_raster(img)

# --- Send to printer ---
ser = serial.Serial(PORT, baudrate=BAUD, bytesize=8, parity='N', stopbits=1, timeout=2)
time.sleep(0.5)

# Initialize
ser.write(b'\x1b\x40')
time.sleep(0.3)

# GS v 0 — Print raster bit image
# Format: GS v 0 m xL xH yL yH d1...dk
# m=0 (normal), xL/xH = width in bytes, yL/yH = height in dots
ser.write(b'\x1d\x76\x30\x00')
ser.write(struct.pack('<HH', wb, h))
ser.write(raster_data)
time.sleep(1)

# Feed and cut (0 extra feed lines — content starts higher)
ser.write(b'\x1d\x56\x42\x00')
time.sleep(0.5)

ser.close()
print(f"Label sent! ({LABEL_W}x{LABEL_H} dots, {wb}x{h} raster)")
