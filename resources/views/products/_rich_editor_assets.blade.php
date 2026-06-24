@once
    @push('styles')
        <style>
            .product-rich-shell { border: 1px solid var(--border); border-radius: 8px; background: #fff; overflow: hidden; }
            .product-rich-toolbar { min-height: 42px; display: flex; align-items: center; gap: 6px; padding: 8px; border-bottom: 1px solid var(--border); background: #fffdfb; flex-wrap: wrap; }
            .product-rich-toolbar button { min-width: 34px; min-height: 32px; border: 1px solid var(--border); border-radius: 7px; background: var(--surface); color: var(--text); font: inherit; font-weight: 760; padding: 5px 9px; cursor: pointer; }
            .product-rich-toolbar button:hover, .product-rich-toolbar button.active { color: var(--green-dark); background: var(--green-soft); }
            .product-rich-editor { min-height: 260px; max-height: 520px; overflow-y: auto; padding: 14px 16px; outline: none; line-height: 1.6; overflow-wrap: anywhere; }
            .product-rich-editor:empty::before { content: attr(data-placeholder); color: var(--muted); }
            .product-rich-editor p { margin: 0 0 10px; }
            .product-rich-editor ul, .product-rich-editor ol { margin: 0 0 10px 22px; padding: 0; }
            .product-rich-editor table { width: 100%; border-collapse: collapse; margin: 10px 0; }
            .product-rich-editor td, .product-rich-editor th { border: 1px solid var(--border); padding: 7px 8px; white-space: normal; }
            .product-rich-html { min-height: 260px; max-height: 520px; border: 0; border-radius: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; line-height: 1.45; resize: vertical; }
            .product-rich-pane[hidden] { display: none; }
        </style>
    @endpush

    @push('scripts')
        <script>
            (() => {
                const editors = [];

                function command(name, value = null) {
                    document.execCommand(name, false, value);
                }

                function enhanceTextarea(textarea, index) {
                    if (textarea.dataset.richReady === '1') {
                        return;
                    }

                    textarea.dataset.richReady = '1';

                    const shell = document.createElement('div');
                    shell.className = 'product-rich-shell';

                    const toolbar = document.createElement('div');
                    toolbar.className = 'product-rich-toolbar';
                    toolbar.addEventListener('mousedown', (event) => event.preventDefault());

                    let editor;
                    let savedRange = null;

                    function saveSelection() {
                        const selection = window.getSelection();

                        if (!editor || !selection || selection.rangeCount === 0) {
                            return;
                        }

                        const range = selection.getRangeAt(0);

                        if (editor.contains(range.commonAncestorContainer)) {
                            savedRange = range.cloneRange();
                        }
                    }

                    function focusEditor() {
                        editor.focus({ preventScroll: true });

                        if (savedRange) {
                            const selection = window.getSelection();

                            if (selection) {
                                selection.removeAllRanges();
                                selection.addRange(savedRange);
                            }
                        }
                    }

                    const visualButton = document.createElement('button');
                    visualButton.type = 'button';
                    visualButton.textContent = 'Wizualnie';
                    visualButton.className = 'active';

                    const htmlButton = document.createElement('button');
                    htmlButton.type = 'button';
                    htmlButton.textContent = 'HTML';

                    const buttons = [
                        ['B', 'bold'],
                        ['I', 'italic'],
                        ['Lista', 'insertUnorderedList'],
                        ['Numeracja', 'insertOrderedList'],
                        ['Cofnij', 'undo'],
                    ].map(([label, action]) => {
                        const button = document.createElement('button');
                        button.type = 'button';
                        button.textContent = label;
                        button.addEventListener('click', () => {
                            focusEditor();
                            command(action);
                            saveSelection();
                            syncVisualToHtml();
                        });

                        return button;
                    });

                    const linkButton = document.createElement('button');
                    linkButton.type = 'button';
                    linkButton.textContent = 'Link';
                    linkButton.addEventListener('click', () => {
                        focusEditor();
                        const url = window.prompt('Adres linku');

                        if (url) {
                            command('createLink', url);
                            saveSelection();
                            syncVisualToHtml();
                        }
                    });

                    toolbar.append(visualButton, htmlButton, ...buttons, linkButton);

                    const visualPane = document.createElement('div');
                    visualPane.className = 'product-rich-pane';

                    editor = document.createElement('div');
                    editor.className = 'product-rich-editor';
                    editor.contentEditable = 'true';
                    editor.dataset.placeholder = 'Wpisz opis produktu...';
                    editor.innerHTML = textarea.value || '';
                    editor.addEventListener('input', () => {
                        saveSelection();
                        syncVisualToHtml();
                    });
                    editor.addEventListener('keyup', saveSelection);
                    editor.addEventListener('mouseup', saveSelection);
                    editor.addEventListener('focus', saveSelection);
                    visualPane.append(editor);

                    const htmlPane = document.createElement('div');
                    htmlPane.className = 'product-rich-pane';
                    textarea.classList.add('product-rich-html');

                    textarea.parentNode.insertBefore(shell, textarea);
                    htmlPane.append(textarea);
                    htmlPane.hidden = true;
                    shell.append(toolbar, visualPane, htmlPane);

                    let mode = 'visual';

                    function syncVisualToHtml() {
                        textarea.value = editor.innerHTML.trim();
                    }

                    function syncHtmlToVisual() {
                        editor.innerHTML = textarea.value || '';
                    }

                    function setMode(nextMode) {
                        if (nextMode === mode) {
                            return;
                        }

                        if (nextMode === 'html') {
                            syncVisualToHtml();
                        } else {
                            syncHtmlToVisual();
                        }

                        mode = nextMode;
                        visualPane.hidden = mode !== 'visual';
                        htmlPane.hidden = mode !== 'html';
                        visualButton.classList.toggle('active', mode === 'visual');
                        htmlButton.classList.toggle('active', mode === 'html');
                    }

                    visualButton.addEventListener('click', () => setMode('visual'));
                    htmlButton.addEventListener('click', () => setMode('html'));
                    textarea.addEventListener('input', () => {
                        if (mode === 'html') {
                            syncHtmlToVisual();
                        }
                    });

                    editors.push({ textarea, sync: syncVisualToHtml });
                }

                document.querySelectorAll('textarea[data-rich-product-editor]').forEach(enhanceTextarea);

                document.querySelectorAll('form').forEach((form) => {
                    form.addEventListener('submit', () => {
                        editors
                            .filter((entry) => form.contains(entry.textarea))
                            .forEach((entry) => entry.sync());
                    });
                });
            })();
        </script>
    @endpush
@endonce
