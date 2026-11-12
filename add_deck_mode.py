import re

with open('Billing/Presentacion_Slides_HD.html', 'r', encoding='utf-8') as f:
    html = f.read()

# Add styling for presentation controls and full-screen scaling
presentation_css = """
        /* Estilos de Modo Presentación Interactivo */
        @media screen {
            body.deck-mode {
                margin: 0;
                padding: 0;
                overflow: hidden;
                width: 100vw;
                height: 100vh;
                background-color: #050811;
                display: flex;
                align-items: center;
                justify-content: center;
            }

            body.deck-mode .slide-wrapper {
                position: relative;
                width: 1920px;
                height: 1080px;
                transform-origin: center center;
                box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.7);
            }

            body.deck-mode .slide {
                display: none;
                position: absolute;
                top: 0;
                left: 0;
                width: 1920px;
                height: 1080px;
            }

            body.deck-mode .slide.active-slide {
                display: flex;
            }

            /* Barra de control flotante */
            .floating-nav {
                position: fixed;
                bottom: 24px;
                left: 50%;
                transform: translateX(-50%);
                display: flex;
                align-items: center;
                gap: 12px;
                background: rgba(15, 23, 42, 0.88);
                backdrop-filter: blur(12px);
                border: 1px solid rgba(56, 189, 248, 0.25);
                border-radius: 9999px;
                padding: 8px 18px;
                box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.5);
                z-index: 99999;
                user-select: none;
                transition: opacity 0.3s ease;
            }

            .floating-nav:hover {
                background: rgba(15, 23, 42, 0.98);
                border-color: rgba(56, 189, 248, 0.5);
            }

            .nav-btn {
                background: rgba(255, 255, 255, 0.08);
                color: #f8fafc;
                border: 1px solid rgba(255, 255, 255, 0.15);
                padding: 6px 14px;
                border-radius: 9999px;
                font-family: inherit;
                font-size: 13px;
                font-weight: 600;
                cursor: pointer;
                display: flex;
                align-items: center;
                gap: 6px;
                transition: all 0.2s ease;
            }

            .nav-btn:hover {
                background: #38bdf8;
                color: #0f172a;
                border-color: #38bdf8;
            }

            .nav-counter {
                color: #94a3b8;
                font-size: 13px;
                font-weight: 600;
                padding: 0 8px;
            }

            .nav-counter strong {
                color: #38bdf8;
            }
        }

        @media print {
            .floating-nav {
                display: none !important;
            }
            .slide {
                display: flex !important;
                page-break-after: always;
                break-after: page;
            }
        }
"""

presentation_js = """
    <!-- Controles de Presentación Flotantes -->
    <div class="floating-nav" id="deck-nav">
        <button class="nav-btn" onclick="prevSlide()" title="Anterior (Flecha Izquierda / Backspace)">◀ Anterior</button>
        <span class="nav-counter" id="slide-indicator">Diapositiva <strong>1</strong> / 18</span>
        <button class="nav-btn" onclick="nextSlide()" title="Siguiente (Flecha Derecha / Espacio)">Siguiente ▶</button>
        <span style="color: rgba(255,255,255,0.2);">|</span>
        <button class="nav-btn" onclick="toggleFullscreen()" title="Pantalla Completa (F)">⛶ Pantalla Completa</button>
        <button class="nav-btn" onclick="toggleViewMode()" id="view-mode-btn" title="Alternar Vista">Modo Lista</button>
    </div>

    <script>
        let currentSlide = 0;
        let isDeckMode = true;
        const slides = document.querySelectorAll('.slide');
        const totalSlides = slides.length;
        const indicator = document.getElementById('slide-indicator');
        const viewModeBtn = document.getElementById('view-mode-btn');

        // Wrap slides in a scalable container
        const wrapper = document.createElement('div');
        wrapper.className = 'slide-wrapper';
        wrapper.id = 'slide-wrapper';
        document.body.classList.add('deck-mode');

        // Move slides into wrapper
        slides.forEach((s, idx) => {
            s.setAttribute('data-index', idx);
            wrapper.appendChild(s);
        });
        document.body.prepend(wrapper);

        function updateSlide() {
            if (!isDeckMode) return;
            slides.forEach((s, idx) => {
                if (idx === currentSlide) {
                    s.classList.add('active-slide');
                } else {
                    s.classList.remove('active-slide');
                }
            });
            indicator.innerHTML = `Diapositiva <strong>${currentSlide + 1}</strong> / ${totalSlides}`;
        }

        function nextSlide() {
            if (currentSlide < totalSlides - 1) {
                currentSlide++;
                updateSlide();
            }
        }

        function prevSlide() {
            if (currentSlide > 0) {
                currentSlide--;
                updateSlide();
            }
        }

        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {});
            } else {
                if (document.exitFullscreen) {
                    document.exitFullscreen();
                }
            }
        }

        function toggleViewMode() {
            isDeckMode = !isDeckMode;
            if (isDeckMode) {
                document.body.classList.add('deck-mode');
                viewModeBtn.innerText = 'Modo Lista';
                fitToScreen();
                updateSlide();
            } else {
                document.body.classList.remove('deck-mode');
                wrapper.style.transform = 'none';
                slides.forEach(s => s.classList.add('active-slide'));
                viewModeBtn.innerText = 'Modo Presentación';
            }
        }

        function fitToScreen() {
            if (!isDeckMode) return;
            const w = window.innerWidth;
            const h = window.innerHeight;
            const scaleX = w / 1920;
            const scaleY = h / 1080;
            const scale = Math.min(scaleX, scaleY) * 0.98;
            wrapper.style.transform = `scale(${scale})`;
        }

        window.addEventListener('resize', fitToScreen);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'PageDown') {
                e.preventDefault();
                nextSlide();
            } else if (e.key === 'ArrowLeft' || e.key === 'Backspace' || e.key === 'PageUp') {
                e.preventDefault();
                prevSlide();
            } else if (e.key === 'f' || e.key === 'F') {
                toggleFullscreen();
            } else if (e.key === 'Home') {
                currentSlide = 0;
                updateSlide();
            } else if (e.key === 'End') {
                currentSlide = totalSlides - 1;
                updateSlide();
            }
        });

        // Initialize
        fitToScreen();
        updateSlide();
    </script>
"""

# Insert CSS into <style>
html = html.replace('</style>', presentation_css + '\n    </style>')

# Insert JS before </body>
html = html.replace('</body>', presentation_js + '\n</body>')

with open('Billing/Presentacion_Slides_HD.html', 'w', encoding='utf-8') as f:
    f.write(html)

print("Updated Billing/Presentacion_Slides_HD.html with interactive deck mode and auto-scaling!")
