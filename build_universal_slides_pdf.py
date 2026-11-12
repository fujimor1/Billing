import os
import re
from bs4 import BeautifulSoup
from PIL import Image
import fitz
from playwright.sync_api import sync_playwright

html_path = os.path.abspath('Billing/Presentacion_Slides_HD.html').replace('\\', '/')
output_dir = os.path.abspath('Billing/slides_hd')
jpg_dir = os.path.abspath('Billing/slides_jpg')
os.makedirs(output_dir, exist_ok=True)
os.makedirs(jpg_dir, exist_ok=True)

# 1. Parse HTML to extract text per slide
with open('Billing/Presentacion_Slides_HD.html', 'r', encoding='utf-8') as f:
    soup = BeautifulSoup(f.read(), 'html.parser')

slides_dom = soup.find_all('div', class_='slide')
slides_text = []
for s in slides_dom:
    # get clean text
    txt = s.get_text(separator=' ', strip=True)
    slides_text.append(txt)

print(f"Extracted text for {len(slides_text)} slides")

# 2. Capture high-res PNG screenshots of each slide
with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={'width': 1920, 'height': 1080})
    page.goto('file:///' + html_path)
    page.wait_for_timeout(2000)

    slides = page.query_selector_all('.slide')
    print('Found DOM slides:', len(slides))
    
    image_paths = []
    for i, slide in enumerate(slides):
        png_path = os.path.join(output_dir, f'slide_{i+1:02d}.png')
        slide.screenshot(path=png_path)
        
        # Convert to high-quality optimized JPEG (quality=95)
        jpg_path = os.path.join(jpg_dir, f'slide_{i+1:02d}.jpg')
        img = Image.open(png_path).convert('RGB')
        img.save(jpg_path, 'JPEG', quality=95, optimize=True)
        image_paths.append(jpg_path)
        print(f"Slide {i+1} captured and converted to JPG ({os.path.getsize(jpg_path)} bytes)")
    
    browser.close()

# 3. Create PDF with standard 16:9 widescreen dimensions (960 x 540 pt)
target_pdf = os.path.abspath('Billing/Presentacion_PHPUnit_Slides_HD.pdf')
doc = fitz.open()

for i, img_path in enumerate(image_paths):
    page = doc.new_page(width=960, height=540)
    rect = fitz.Rect(0, 0, 960, 540)
    
    # Insert high-resolution background image
    page.insert_image(rect, filename=img_path)
    
    # Insert invisible searchable text layer
    text = slides_text[i]
    # Place text across page in small invisible font
    # render_mode=3 is PDF standard for invisible text (neither fill nor stroke)
    textbox_rect = fitz.Rect(20, 20, 940, 520)
    page.insert_textbox(textbox_rect, text, fontsize=6, render_mode=3)

# Save with deflate compression
doc.save(target_pdf, deflate=True)
doc.close()

size_mb = os.path.getsize(target_pdf) / (1024 * 1024)
print(f"SUCCESS: Generated {target_pdf} ({size_mb:.2f} MB)")

# 4. Verify the newly generated PDF
doc_check = fitz.open(target_pdf)
print(f"Verification: {len(doc_check)} pages in PDF")
for i, p in enumerate(doc_check):
    text_len = len(p.get_text())
    pix = p.get_pixmap(dpi=72)
    min_v, max_v = min(pix.samples), max(pix.samples)
    print(f"  Slide {i+1:02d}: {p.rect.width}x{p.rect.height} pt, text={text_len} chars, pixel range=[{min_v}, {max_v}]")

doc_check.close()
