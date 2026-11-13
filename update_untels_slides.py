# -*- coding: utf-8 -*-
with open('Billing/Presentacion_Slides_HD.html', 'r', encoding='utf-8') as f:
    html = f.read()

# Replace UNI with UNTELS and insert the official UNTELS logo
old_header = 'UNIVERSIDAD NACIONAL DE INGENIERÍA • FACULTAD DE INGENIERÍA DE SISTEMAS'
new_header = '<img src="assets/logo_untels.png" style="height: 90px; margin-bottom: 12px; filter: drop-shadow(0 4px 12px rgba(0,0,0,0.5));"><br>UNIVERSIDAD NACIONAL TECNOLÓGICA DE LIMA SUR (UNTELS) • INGENIERÍA DE SISTEMAS'
html = html.replace(old_header, new_header)

old_footer = 'Universidad Nacional de Ingeniería • Facultad de Sistemas'
new_footer = 'UNIVERSIDAD NACIONAL TECNOLÓGICA DE LIMA SUR (UNTELS) • FACULTAD DE INGENIERÍA Y GESTIÓN'
html = html.replace(old_footer, new_footer)

with open('Billing/Presentacion_Slides_HD.html', 'w', encoding='utf-8') as f:
    f.write(html)

print("SUCCESS: Updated Presentacion_Slides_HD.html with UNTELS logo and header")
