window.sipPdfPreview = function () {
    let pdfDocument = null;

    return {
        loading: true,
        error: '',
        observer: null,
        renderedPages: new Set(),

        async load(element) {
            try {
                const pdfjs = await import(element.dataset.pdfjsUrl);
                pdfjs.GlobalWorkerOptions.workerSrc = element.dataset.pdfWorkerUrl;

                const response = await fetch(element.dataset.pdfUrl, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/pdf' }
                });

                if (!response.ok) {
                    throw new Error(`PDF request failed with status ${response.status}`);
                }

                const data = await response.arrayBuffer();
                pdfDocument = await pdfjs.getDocument({
                    data: new Uint8Array(data),
                    cMapUrl: element.dataset.cmapUrl,
                    cMapPacked: true,
                    standardFontDataUrl: element.dataset.standardFontUrl
                }).promise;

                this.observer = new IntersectionObserver((entries) => {
                    entries.forEach((entry) => {
                        if (entry.isIntersecting) {
                            this.renderPage(entry.target);
                            this.observer.unobserve(entry.target);
                        }
                    });
                }, {
                    root: element.querySelector('.sip-pdf-preview__viewport'),
                    rootMargin: '400px 0px'
                });

                const pages = element.querySelector('.sip-pdf-preview__pages');

                for (let pageNumber = 1; pageNumber <= pdfDocument.numPages; pageNumber += 1) {
                    const pageElement = document.createElement('section');
                    pageElement.className = 'sip-pdf-preview__page';
                    pageElement.dataset.pageNumber = String(pageNumber);
                    pageElement.setAttribute('aria-label', `Page ${pageNumber}`);
                    pageElement.innerHTML = '<div class="sip-pdf-preview__page-loading">Loading page…</div>';
                    pages.append(pageElement);
                    this.observer.observe(pageElement);
                }

                this.loading = false;
            } catch (error) {
                console.error('SIP PDF preview failed.', error);
                this.error = 'This PDF could not be displayed. Use Download to open the original file.';
                this.loading = false;
            }
        },

        async renderPage(pageElement) {
            const pageNumber = Number(pageElement.dataset.pageNumber);

            if (!pdfDocument || this.renderedPages.has(pageNumber)) {
                return;
            }

            this.renderedPages.add(pageNumber);

            try {
                const page = await pdfDocument.getPage(pageNumber);
                const baseViewport = page.getViewport({ scale: 1 });
                const maxWidth = Math.max(1, pageElement.clientWidth - 24);
                const viewport = page.getViewport({
                    scale: Math.min(1.5, maxWidth / baseViewport.width)
                });
                const outputScale = Math.min(window.devicePixelRatio || 1, 2);
                const canvas = document.createElement('canvas');
                const context = canvas.getContext('2d');

                canvas.width = Math.floor(viewport.width * outputScale);
                canvas.height = Math.floor(viewport.height * outputScale);
                canvas.style.width = `${Math.floor(viewport.width)}px`;
                canvas.style.height = `${Math.floor(viewport.height)}px`;
                canvas.setAttribute('role', 'img');
                canvas.setAttribute('aria-label', `SIP page ${pageNumber}`);
                pageElement.replaceChildren(canvas);

                await page.render({
                    canvasContext: context,
                    viewport,
                    transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0]
                }).promise;
            } catch (error) {
                console.error(`SIP PDF page ${pageNumber} could not be rendered.`, error);
                this.renderedPages.delete(pageNumber);
                pageElement.innerHTML = '<div class="sip-pdf-preview__page-error">This page could not be displayed.</div>';
            }
        },

        destroy() {
            this.observer?.disconnect();
            pdfDocument?.destroy();
        }
    };
};
