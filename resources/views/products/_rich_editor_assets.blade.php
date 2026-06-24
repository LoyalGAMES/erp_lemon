@once
    @push('styles')
        <style>
            .product-rich-shell { border: 1px solid var(--border); border-radius: 8px; background: #fffdfb; overflow: hidden; }
            .product-rich-toolbar { min-height: 42px; display: flex; align-items: center; gap: 8px; padding: 8px; border-bottom: 1px solid var(--border); background: rgba(134, 115, 100, .06); }
            .product-rich-toolbar button { border: 1px solid var(--border); border-radius: 7px; background: var(--surface); color: var(--text); font: inherit; font-weight: 760; padding: 6px 9px; cursor: pointer; }
            .product-rich-toolbar button.active { color: var(--green-dark); background: var(--green-soft); }
            .product-rich-visual { min-height: 220px; padding: 16px; background: #fff; }
            .product-rich-visual .codex-editor { z-index: 1; }
            .product-rich-fallback { min-height: 190px; outline: none; line-height: 1.55; }
            .product-rich-html { min-height: 220px; border: 0; border-radius: 0; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", monospace; font-size: 12px; line-height: 1.45; resize: vertical; }
            .product-rich-pane[hidden] { display: none; }
        </style>
    @endpush

    @push('scripts')
        <script src="https://cdn.jsdelivr.net/npm/@editorjs/editorjs@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/@editorjs/header@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/@editorjs/list@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/@editorjs/quote@latest"></script>
        <script src="https://cdn.jsdelivr.net/npm/@editorjs/table@latest"></script>
        <script>
            (() => {
                const editorEntries = [];
                const escapeHtml = (value) => String(value ?? '')
                    .replaceAll('&', '&amp;')
                    .replaceAll('<', '&lt;')
                    .replaceAll('>', '&gt;')
                    .replaceAll('"', '&quot;')
                    .replaceAll("'", '&#039;');

                function htmlToEditorData(html) {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(`<main>${html || ''}</main>`, 'text/html');
                    const nodes = Array.from(doc.querySelector('main')?.children || []);
                    const blocks = [];

                    if (nodes.length === 0 && String(html || '').trim() !== '') {
                        blocks.push({ type: 'paragraph', data: { text: String(html || '') } });
                    }

                    nodes.forEach((node) => {
                        const tag = node.tagName.toLowerCase();

                        if (/^h[1-6]$/.test(tag)) {
                            blocks.push({ type: 'header', data: { text: node.innerHTML, level: Math.min(6, Math.max(1, Number(tag.slice(1)))) } });
                            return;
                        }

                        if (tag === 'ul' || tag === 'ol') {
                            blocks.push({
                                type: 'list',
                                data: {
                                    style: tag === 'ol' ? 'ordered' : 'unordered',
                                    items: Array.from(node.children).map((item) => item.innerHTML),
                                },
                            });
                            return;
                        }

                        if (tag === 'blockquote') {
                            blocks.push({ type: 'quote', data: { text: node.innerHTML, caption: '', alignment: 'left' } });
                            return;
                        }

                        if (tag === 'table') {
                            blocks.push({
                                type: 'table',
                                data: {
                                    withHeadings: node.querySelectorAll('th').length > 0,
                                    content: Array.from(node.rows).map((row) => Array.from(row.cells).map((cell) => cell.innerHTML)),
                                },
                            });
                            return;
                        }

                        blocks.push({ type: 'paragraph', data: { text: node.innerHTML || node.textContent || '' } });
                    });

                    return {
                        time: Date.now(),
                        blocks: blocks.length > 0 ? blocks : [{ type: 'paragraph', data: { text: '' } }],
                    };
                }

                function listItemsToHtml(items) {
                    return (items || []).map((item) => {
                        if (typeof item === 'string') {
                            return `<li>${item}</li>`;
                        }

                        const nested = item.items?.length ? listItemsToHtml(item.items) : '';

                        return `<li>${item.content || ''}${nested ? `<ul>${nested}</ul>` : ''}</li>`;
                    }).join('');
                }

                function editorDataToHtml(data) {
                    return (data?.blocks || []).map((block) => {
                        const blockData = block.data || {};

                        if (block.type === 'header') {
                            const level = Math.min(6, Math.max(1, Number(blockData.level || 2)));
                            return `<h${level}>${blockData.text || ''}</h${level}>`;
                        }

                        if (block.type === 'list') {
                            const tag = blockData.style === 'ordered' ? 'ol' : 'ul';
                            return `<${tag}>${listItemsToHtml(blockData.items || [])}</${tag}>`;
                        }

                        if (block.type === 'quote') {
                            const caption = blockData.caption ? `<cite>${blockData.caption}</cite>` : '';
                            return `<blockquote>${blockData.text || ''}${caption}</blockquote>`;
                        }

                        if (block.type === 'table') {
                            const rows = (blockData.content || []).map((row) => `<tr>${(row || []).map((cell) => `<td>${cell || ''}</td>`).join('')}</tr>`).join('');
                            return `<table>${rows}</table>`;
                        }

                        return `<p>${blockData.text || ''}</p>`;
                    }).join("\n");
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
                    const visualButton = document.createElement('button');
                    visualButton.type = 'button';
                    visualButton.textContent = 'Wizualnie';
                    visualButton.className = 'active';
                    const htmlButton = document.createElement('button');
                    htmlButton.type = 'button';
                    htmlButton.textContent = 'HTML';
                    toolbar.append(visualButton, htmlButton);
                    const visualPane = document.createElement('div');
                    visualPane.className = 'product-rich-pane product-rich-visual';
                    const holder = document.createElement('div');
                    holder.id = `product-rich-editor-${Date.now()}-${index}`;
                    visualPane.append(holder);
                    const htmlPane = document.createElement('div');
                    htmlPane.className = 'product-rich-pane';
                    textarea.classList.add('product-rich-html');

                    textarea.parentNode.insertBefore(shell, textarea);
                    htmlPane.append(textarea);
                    shell.append(toolbar, visualPane, htmlPane);
                    htmlPane.hidden = true;

                    const entry = { textarea, visualPane, htmlPane, mode: 'visual', editor: null, fallback: null };
                    const HeaderTool = window.Header;
                    const ListTool = window.EditorjsList || window.List;
                    const QuoteTool = window.Quote;
                    const TableTool = window.Table;

                    if (window.EditorJS) {
                        const tools = {};

                        if (HeaderTool) {
                            tools.header = { class: HeaderTool, inlineToolbar: true };
                        }

                        if (ListTool) {
                            tools.list = { class: ListTool, inlineToolbar: true };
                        }

                        if (QuoteTool) {
                            tools.quote = { class: QuoteTool, inlineToolbar: true };
                        }

                        if (TableTool) {
                            tools.table = { class: TableTool, inlineToolbar: true };
                        }

                        entry.editor = new EditorJS({
                            holder,
                            data: htmlToEditorData(textarea.value),
                            tools,
                            minHeight: 180,
                        });
                    } else {
                        const fallback = document.createElement('div');
                        fallback.className = 'product-rich-fallback';
                        fallback.contentEditable = 'true';
                        fallback.innerHTML = textarea.value || '';
                        holder.replaceWith(fallback);
                        entry.fallback = fallback;
                    }

                    async function syncVisualToHtml() {
                        if (entry.editor) {
                            await entry.editor.isReady;
                            textarea.value = editorDataToHtml(await entry.editor.save());
                        } else if (entry.fallback) {
                            textarea.value = entry.fallback.innerHTML;
                        }
                    }

                    async function setMode(mode) {
                        if (mode === 'html') {
                            await syncVisualToHtml();
                        }

                        if (mode === 'visual' && entry.mode === 'html') {
                            if (entry.editor) {
                                await entry.editor.isReady;
                                await entry.editor.blocks.render(htmlToEditorData(textarea.value));
                            } else if (entry.fallback) {
                                entry.fallback.innerHTML = textarea.value || '';
                            }
                        }

                        entry.mode = mode;
                        visualPane.hidden = mode !== 'visual';
                        htmlPane.hidden = mode !== 'html';
                        visualButton.classList.toggle('active', mode === 'visual');
                        htmlButton.classList.toggle('active', mode === 'html');
                    }

                    visualButton.addEventListener('click', () => setMode('visual'));
                    htmlButton.addEventListener('click', () => setMode('html'));
                    entry.sync = syncVisualToHtml;
                    editorEntries.push(entry);
                }

                document.querySelectorAll('textarea[data-rich-product-editor]').forEach(enhanceTextarea);

                document.querySelectorAll('form').forEach((form) => {
                    form.addEventListener('submit', async (event) => {
                        const entries = editorEntries.filter((entry) => form.contains(entry.textarea));

                        if (entries.length === 0 || form.dataset.richSubmitting === '1') {
                            return;
                        }

                        event.preventDefault();

                        for (const entry of entries) {
                            await entry.sync();
                        }

                        form.dataset.richSubmitting = '1';
                        HTMLFormElement.prototype.submit.call(form);
                    });
                });
            })();
        </script>
    @endpush
@endonce
