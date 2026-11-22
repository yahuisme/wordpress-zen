const zenInit = () => {

    const colorSchemeQuery = window.matchMedia ? window.matchMedia('(prefers-color-scheme: dark)') : null;
    const themeToggle = document.getElementById('theme-toggle');
    const themeToggleIcon = themeToggle ? themeToggle.querySelector('i') : null;
    const themeToggleLabel = themeToggle ? themeToggle.querySelector('[data-theme-toggle-label]') : null;
    const themeStorageKey = 'zen-theme-mode';
    const themeModes = ['auto', 'light', 'dark'];
    const themeMeta = {
        auto: { icon: 'ph-circle-half', label: '跟随系统' },
        light: { icon: 'ph-sun', label: '浅色模式' },
        dark: { icon: 'ph-moon', label: '深色模式' },
    };
    const themeDefaultMode = (window.zenSettings && window.zenSettings.theme_mode_default && themeModes.includes(window.zenSettings.theme_mode_default)) ? window.zenSettings.theme_mode_default : 'auto';
    let themeMode = themeDefaultMode;
    const zenSearchShortcut = Number(window.zenSettings?.search_shortcut) === 1;
    const reduceMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const reduceMotion = reduceMotionQuery ? reduceMotionQuery.matches : false;

    const footerNav = document.querySelector('.zen-site-footer .zen-footer-navigation');
    const footerLinks = footerNav?.nextElementSibling;
    if (footerLinks?.classList.contains('zen-footer-links') && window.matchMedia && !window.CSS?.supports('reading-flow', 'flex-visual')) {
        const desktop = window.matchMedia('(min-width: 640px)');
        const updateFooterOrder = ({ matches }) => {
            const [first, second] = matches ? [footerLinks, footerNav] : [footerNav, footerLinks];
            if (first.nextElementSibling === second) return;
            // Keep the currently focused subtree in place when crossing the breakpoint.
            if (first.contains(document.activeElement)) first.after(second);
            else second.before(first);
        };
        updateFooterOrder(desktop);
        if (desktop.addEventListener) desktop.addEventListener('change', updateFooterOrder);
        else if (desktop.addListener) desktop.addListener(updateFooterOrder);
    }

    document.querySelectorAll('.zen-category-nav').forEach((nav) => {
        const track = nav.querySelector('.zen-category-track');
        const prev = nav.querySelector('.zen-category-prev');
        const next = nav.querySelector('.zen-category-next');
        if (!track || !prev || !next) return;
        const direction = getComputedStyle(track).direction === 'rtl' ? -1 : 1;
        nav.classList.toggle('is-rtl', direction === -1);
        let preservingEdgeFocus = false;
        const update = () => {
            const padding = nav.classList.contains('has-overflow') ? 32 : 0;
            const overflowing = track.scrollWidth - padding > track.clientWidth + 1;
            nav.classList.toggle('has-overflow', overflowing);
            const offset = direction * track.scrollLeft;
            const hidePrev = !overflowing || offset <= 1;
            const hideNext = !overflowing || offset + track.clientWidth >= track.scrollWidth - 1;
            const active = document.activeElement;
            if ((hidePrev && active === prev) || (hideNext && active === next)) {
                const links = track.querySelectorAll('a');
                // Continue at the reached category before its focused arrow disappears.
                preservingEdgeFocus = true;
                links[active === prev ? 0 : links.length - 1]?.focus({ preventScroll: true });
                preservingEdgeFocus = false;
            }
            prev.hidden = hidePrev;
            next.hidden = hideNext;
        };
        const reveal = (link) => {
            const target = link.getBoundingClientRect();
            const viewport = track.getBoundingClientRect();
            track.scrollLeft += target.left - viewport.left + (target.width - viewport.width) / 2;
            update();
        };
        [prev, next].forEach((button, index) => {
            button.addEventListener('click', () => track.scrollBy({
                left: direction * (index ? 1 : -1) * Math.max(64, track.clientWidth - 96),
                behavior: reduceMotion ? 'auto' : 'smooth',
            }));
        });
        track.addEventListener('focusin', (event) => {
            if (!event.target.matches('a')) return;
            nav.classList.add('has-link-focus');
            if (!preservingEdgeFocus) reveal(event.target);
        });
        track.addEventListener('focusout', (event) => {
            if (!event.relatedTarget?.matches('a') || !track.contains(event.relatedTarget)) {
                nav.classList.remove('has-link-focus');
            }
        });
        track.addEventListener('scroll', update, { passive: true });
        window.addEventListener('resize', update);
        if (window.ResizeObserver) new ResizeObserver(update).observe(track);
        update();
        const current = track.querySelector('[aria-current="page"]');
        if (current && nav.classList.contains('has-overflow')) reveal(current);
        const initialOffset = track.scrollLeft;
        if (document.fonts) document.fonts.ready.then(() => {
            update();
            const active = document.activeElement;
            const target = track.contains(active) && active.matches('a') ? active :
                (track.scrollLeft === initialOffset && active !== prev && active !== next ? current : null);
            if (target && nav.classList.contains('has-overflow')) reveal(target);
        });
    });

    const archiveYears = document.getElementById('zen-archive-years');
    const archiveMore = document.querySelector('.zen-archive-more');
    if (archiveYears && archiveMore) {
        const years = [...archiveYears.querySelectorAll('.zen-archive-group')];
        let shown = 2;
        if (years.length > shown) {
            archiveMore.addEventListener('click', () => {
                const first = years[shown];
                const next = Math.min(shown + 2, years.length);
                for (; shown < next; shown++) years[shown].hidden = false;
                const heading = first?.querySelector('.zen-archive-year-head');
                if (heading) heading.focus({ preventScroll: true });
                archiveMore.hidden = shown >= years.length;
            });
            // Hide content only after the enhancement is ready; without JS every year remains reachable.
            years.slice(shown).forEach(year => { year.hidden = true; });
            archiveMore.hidden = false;
        }
    }

    const getStoredThemeMode = () => {
        try {
            const storedMode = window.localStorage.getItem(themeStorageKey);
            return themeModes.includes(storedMode) ? storedMode : themeDefaultMode;
        } catch (error) {
            return themeDefaultMode;
        }
    };

    const setStoredThemeMode = (mode) => {
        try {
            window.localStorage.setItem(themeStorageKey, mode);
        } catch (error) {}
    };

    const applyThemeMode = (mode) => {
        const prefersDark = colorSchemeQuery ? colorSchemeQuery.matches : false;
        const isDark = mode === 'dark' || (mode === 'auto' && prefersDark);
        const meta = themeMeta[mode] || themeMeta.auto;
        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.dataset.themeMode = mode;
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';

        if (themeToggle) {
            themeToggle.setAttribute('aria-label', '切换主题：' + meta.label);
            themeToggle.setAttribute('title', meta.label);
        }

        if (themeToggleLabel) {
            themeToggleLabel.textContent = meta.label;
        }

        if (themeToggleIcon) {
            themeToggleIcon.className = 'ph ' + meta.icon + ' text-xl md:text-lg';
        }
    };

    themeMode = getStoredThemeMode();
    applyThemeMode(themeMode);

    if (colorSchemeQuery) {
        const handleSystemThemeChange = () => {
            if (themeMode === 'auto') applyThemeMode(themeMode);
        };

        if (colorSchemeQuery.addEventListener) {
            colorSchemeQuery.addEventListener('change', handleSystemThemeChange);
        } else if (colorSchemeQuery.addListener) {
            colorSchemeQuery.addListener(handleSystemThemeChange);
        }
    }

    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const currentIndex = themeModes.indexOf(themeMode);
            themeMode = themeModes[(currentIndex + 1) % themeModes.length];
            setStoredThemeMode(themeMode);
            applyThemeMode(themeMode);
        });
        themeToggle.hidden = false;
    }

    // One Zen modal owns focus and the background until its close finishes.
    const searchToggle = document.getElementById('search-toggle');
    let activeModal;
    const coreLightboxOpen = () => document.querySelector('.wp-lightbox-overlay.active') !== null;
    const restoreModalFocus = (trigger) => {
        trigger?.focus({ preventScroll: true });
        if (document.activeElement !== trigger) searchToggle?.focus({ preventScroll: true });
    };
    const focusModal = (container, control) => {
        control?.focus({ preventScroll: true });
        if (!container.contains(document.activeElement)) {
            if (!container.hasAttribute('tabindex')) container.tabIndex = -1;
            container.focus({ preventScroll: true });
        }
    };
    const lockModal = (container, dismiss, control, backdrop) => {
        activeModal = { container, dismiss };
        focusModal(container, control);
        const background = [];
        const isolate = (parent) => {
            for (const child of parent.children) {
                if (child === container || child === backdrop) continue;
                if (child.contains(container) || (backdrop && child.contains(backdrop))) {
                    isolate(child);
                    continue;
                }
                background.push([child, child.hasAttribute('inert'), child.getAttribute('aria-hidden')]);
                child.setAttribute('inert', '');
                child.setAttribute('aria-hidden', 'true');
            }
        };
        isolate(document.body);
        const style = document.body.style;
        const overflow = style.overflow;
        const priority = style.getPropertyPriority('overflow');
        style.setProperty('overflow', 'hidden');
        activeModal.restore = () => {
            style.setProperty('overflow', overflow, priority);
            for (const [node, wasInert, ariaHidden] of background) {
                if (!wasInert) node.removeAttribute('inert');
                if (ariaHidden === null) node.removeAttribute('aria-hidden');
                else node.setAttribute('aria-hidden', ariaHidden);
            }
        };
    };
    const unlockModal = () => {
        activeModal.restore();
        activeModal = null;
    };
    const trapModalTab = (event, container) => {
        const controls = Array.from(container.querySelectorAll('button, a[href], input, select, textarea, [tabindex]')).filter(element =>
            element.tabIndex >= 0 && !element.matches(':disabled, input[type="hidden"]') &&
            !element.closest('[hidden], [inert], [aria-hidden="true"]') &&
            (element.checkVisibility ? element.checkVisibility({ visibilityProperty: true }) :
                element.getClientRects().length > 0 && getComputedStyle(element).visibility === 'visible')
        );
        const activeIndex = controls.indexOf(document.activeElement);
        if (activeIndex < 0 || (event.shiftKey ? activeIndex === 0 : activeIndex === controls.length - 1)) {
            event.preventDefault();
            controls[event.shiftKey ? controls.length - 1 : 0]?.focus();
        }
    };

    let searchShortcutHandler;
    document.addEventListener('keydown', (event) => {
        if (event.defaultPrevented || event.isComposing) return;
        if (event.key === 'Tab' && activeModal) {
            trapModalTab(event, activeModal.container);
        } else if (event.key === 'Escape') {
            if (activeModal) {
                event.preventDefault();
                activeModal.dismiss();
            }
        } else {
            searchShortcutHandler?.(event);
        }
    });

    // --- 1. 代码高亮 + 一键复制 ---
    const copyTextToClipboard = async (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(text);
                return;
            } catch (error) {}
        }
        return new Promise((resolve, reject) => {
            const previousFocus = document.activeElement;
            const textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'fixed';
            textarea.style.opacity = '0';
            document.body.appendChild(textarea);
            textarea.select();
            try {
                if (document.execCommand('copy')) {
                    resolve();
                } else {
                    reject(new Error('Copy command failed'));
                }
            } catch (error) {
                reject(error);
            } finally {
                textarea.remove();
                previousFocus?.focus({ preventScroll: true });
            }
        });
    };

    const codeBlocks = [];
    document.querySelectorAll('.entry-content pre, pre.wp-block-code').forEach(block => {
        const code = block.querySelector('code');
        if (!code) return;
        codeBlocks.push({ block, code });
        block.classList.forEach(className => {
            if (className.startsWith('language-')) code.classList.add(className);
        });

        if (!block.parentElement.matches('.zen-code-block')) {
            const copyBtn = document.createElement('button');
            copyBtn.type = 'button';
            copyBtn.className = 'zen-code-copy';
            copyBtn.setAttribute('aria-label', '复制代码');
            const copyIcon = document.createElement('i');
            copyIcon.className = 'ph ph-copy';
            copyIcon.setAttribute('aria-hidden', 'true');
            const copyLabel = document.createElement('span');
            copyLabel.textContent = '复制';
            copyBtn.appendChild(copyIcon);
            copyBtn.appendChild(copyLabel);

            let copyAttempt = 0;
            let copyResetTimer;
            const showCopyResult = (copied, attempt) => {
                if (attempt !== copyAttempt) return;
                copyIcon.className = copied ? 'ph ph-check' : 'ph ph-copy';
                copyLabel.textContent = copied ? '已复制' : '复制失败';
                copyBtn.classList.toggle('is-copied', copied);
                copyBtn.setAttribute('aria-label', copyLabel.textContent);
                copyResetTimer = setTimeout(() => {
                    copyIcon.className = 'ph ph-copy';
                    copyLabel.textContent = '复制';
                    copyBtn.classList.remove('is-copied');
                    copyBtn.setAttribute('aria-label', '复制代码');
                }, 1600);
            };
            copyBtn.addEventListener('click', () => {
                const attempt = ++copyAttempt;
                clearTimeout(copyResetTimer);
                copyTextToClipboard(code.textContent).then(
                    () => showCopyResult(true, attempt),
                    () => showCopyResult(false, attempt)
                );
            });

            const frame = document.createElement('div');
            frame.className = 'zen-code-block';
            block.before(frame);
            frame.append(copyBtn, block);
        }
    });

    // --- 2. Lightbox (A11y: Focus Management & ARIA) ---
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');

    if (lightbox && lightboxImg) {
        const closeBtn = document.getElementById('lightbox-close');
        const images = document.querySelectorAll('.entry-content img, .wp-block-image img');
        const nativeImageOwner = 'a, button, summary, label, .wp-lightbox-container';
        let lightboxTrigger;

        images.forEach(img => {
            if (img.closest(nativeImageOwner)) return;
            img.classList.add('cursor-zoom-in');
            img.setAttribute('tabindex', '0');
            img.setAttribute('role', 'button');
            const imageDescription = (img.getAttribute('aria-label') || img.alt || '').trim();
            img.setAttribute('aria-label', imageDescription ? `${imageDescription}，点击查看大图` : '点击查看大图');

            const openLightbox = (e) => {
                if (e.defaultPrevented || activeModal || img.closest(nativeImageOwner) || coreLightboxOpen()) return;
                e.preventDefault();
                lightboxTrigger = img;

                lightboxImg.src = img.currentSrc || img.src;
                lightboxImg.alt = img.alt || '放大图片';
                if (img.srcset) {
                    // Match the lightbox's width cap, not the inline image's sizes.
                    lightboxImg.sizes = 'min(92vw, 72rem)';
                    lightboxImg.srcset = img.srcset;
                } else {
                    lightboxImg.removeAttribute('srcset');
                    lightboxImg.removeAttribute('sizes');
                }
                lightbox.classList.remove('hidden');
                lockModal(lightbox, closeLightbox, closeBtn);
            };

            img.addEventListener('click', openLightbox);
            img.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    openLightbox(e);
                }
            });
        });

        const closeLightbox = () => {
            if (activeModal?.container !== lightbox) return;
            lightbox.classList.add('hidden');
            unlockModal();
            restoreModalFocus(lightboxTrigger);
        };

        if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) closeLightbox();
        });
    }

    // --- 3. TOC 目录 (Universal Logic) ---
    const focusTocTarget = (target) => {
        if (!target) return;
        if (!target.hasAttribute('tabindex')) {
            target.setAttribute('tabindex', '-1');
            target.addEventListener('blur', () => target.removeAttribute('tabindex'), { once: true });
        }
        // Native hash navigation owns scrolling; focus only sets the next Tab position.
        target.focus({ preventScroll: true });
    };
    const article = document.getElementById('post-content');
    const tocContainer = document.getElementById('toc-container'); // PC Sidebar
    const tocNav = document.getElementById('toc-nav'); // PC Nav Content
    const drawerTocNav = document.getElementById('drawer-toc-nav'); // Drawer Nav Content
    let closeDrawer;
    const headerTocBtn = document.getElementById('header-toc-btn');

    const progressBar = document.getElementById('reading-progress');
    const backToTopBtn = document.getElementById('back-to-top');
    let scrollTicking = false;
    let updateToc;
    let backToTopVisible;

    const updateScrollUI = () => {
        if (progressBar) {
            const scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
            const scrollHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            const scrollPercent = scrollHeight > 0 ? (scrollTop / scrollHeight) * 100 : 0;
            const width = Math.min(100, Math.max(0, scrollPercent)) + '%';
            if (progressBar.style.width !== width) progressBar.style.width = width;
        }

        if (backToTopBtn) {
            const visible = window.scrollY > 300;
            if (visible !== backToTopVisible) {
                backToTopVisible = visible;
                backToTopBtn.disabled = !visible;
                for (const name of ['opacity-0', 'pointer-events-none', 'translate-y-4']) {
                    backToTopBtn.classList.toggle(name, !visible);
                }
            }
        }
    };

    const requestScrollUIUpdate = () => {
        if (!scrollTicking) {
            scrollTicking = true;
            requestAnimationFrame(() => {
                scrollTicking = false;
                updateToc?.();
                updateScrollUI();
            });
        }
    };


    // TOC Generation & Spy
    if (article && (tocNav || drawerTocNav)) {
        const headers = article.querySelectorAll('h2, h3');
        if (headers.length > 0) {
            // 1. 初始化显示
            // 只要有目录内容，就移除 display:none (hidden)
            // 具体的显隐由 CSS @media 查询控制 (xl:block / xl:hidden)
            if (tocContainer) {
                tocContainer.classList.add('zen-toc-ready');
                tocContainer.classList.remove('opacity-0');
            }
            if (headerTocBtn) {
                headerTocBtn.hidden = false;
                headerTocBtn.classList.remove('hidden', 'zen-toc-pending');
                headerTocBtn.classList.add('zen-toc-ready');
            }

            const idCounts = new Map();
            document.querySelectorAll('[id]').forEach((element) => {
                idCounts.set(element.id, (idCounts.get(element.id) || 0) + 1);
            });
            const usedIds = new Set(idCounts.keys());
            const tocItems = Array.from(headers).map((header, index) => {
                const originalId = header.id;
                const baseId = originalId || 'section-' + index;
                let headingId = baseId;
                let suffix = 2;
                if (originalId && idCounts.get(originalId) === 1) {
                    usedIds.delete(originalId);
                }
                while (usedIds.has(headingId)) headingId = baseId + '-' + suffix++;
                header.id = headingId;
                usedIds.add(headingId);
                return {
                    id: headingId,
                    target: header,
                    level: header.tagName.toLowerCase(),
                    text: header.textContent.trim() || `章节 ${index + 1}`,
                };
            });

            const comments = document.getElementById('comments');
            if (comments && !tocItems.some(item => item.id === 'comments')) {
                tocItems.push({ id: 'comments', target: comments, level: 'comments', text: '评论区' });
            }

            const anchorNames = new Set(Array.from(document.querySelectorAll('a[name]'), anchor => anchor.name));
            const hasLiteralAnchor = fragment => usedIds.has(fragment) || anchorNames.has(fragment);
            let aliasCount = 0;
            const encodeTocFragment = (item) => {
                const encoded = encodeURIComponent(item.id);
                if (!hasLiteralAnchor(encoded)) return encoded;
                // Escaping ASCII and varying hex case keeps the decoded ID unchanged.
                const variants = encoded.match(/%[\dA-F]{2}|./g).map(part => {
                    const hex = part[0] === '%' ? part.slice(1) : part.charCodeAt(0).toString(16).toUpperCase();
                    const [a, b] = hex;
                    const forms = [`%${a}${b}`, `%${a.toLowerCase()}${b}`, `%${a}${b.toLowerCase()}`, `%${hex.toLowerCase()}`];
                    return [...new Set(part[0] === '%' ? forms : [part, ...forms])];
                });
                const positions = variants.map(() => 0);
                // Each candidate is distinct; only existing literal anchors can keep the search going.
                for (let index = 0; index < positions.length;) {
                    const fragment = variants.map((choices, i) => choices[positions[i]]).join('');
                    if (!hasLiteralAnchor(fragment)) return fragment;
                    for (index = 0; index < positions.length; index++) {
                        if (++positions[index] < variants[index].length) break;
                        positions[index] = 0;
                    }
                }
                // All spellings are taken. Give the heading an additional anchor, not a new ID.
                const alias = document.createElement('span');
                do { alias.id = `zen-toc-anchor-${++aliasCount}`; } while (hasLiteralAnchor(alias.id));
                alias.setAttribute('aria-hidden', 'true');
                alias.style.display = 'block';
                alias.style.scrollMarginTop = 'inherit';
                item.target.prepend(alias);
                usedIds.add(alias.id);
                return alias.id;
            };

            const createTocLink = (item, nav) => {
                const link = document.createElement('a');
                const paddingClass = item.level === 'h3' ? 'pl-6 text-xs' : 'pl-3 text-sm';
                link.href = '#' + item.id;
                const fragment = link.hash.slice(1);
                let matchesHeading = fragment === item.id;
                // The browser tries the literal fragment before its decoded form.
                if (!matchesHeading && !hasLiteralAnchor(fragment)) {
                    try { matchesHeading = decodeURIComponent(fragment) === item.id; } catch {}
                }
                if (!matchesHeading || item.id.includes(':~:')) {
                    item.fragment ??= encodeTocFragment(item);
                    link.href = '#' + item.fragment;
                }
                link.className = `toc-link ${paddingClass}`;
                link.dataset.target = item.id;
                link.textContent = item.text;
                if (nav === tocNav) item.desktopLink = link;
                else item.drawerLink = link;
                link.addEventListener('click', (event) => {
                    if (event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
                    // Let delegated listeners cancel the native click before changing any UI.
                    setTimeout(() => {
                        if (event.defaultPrevented) return;
                        let revealed = false;
                        for (let ancestor = item.target.parentElement; ancestor; ancestor = ancestor.parentElement) {
                            if (ancestor.tagName === 'DETAILS' && !ancestor.open) {
                                ancestor.open = true;
                                revealed = true;
                            }
                        }
                        // Some browsers do not reveal collapsed ancestors during fragment navigation.
                        if (revealed) item.target.scrollIntoView();
                        if (nav === drawerTocNav) closeDrawer?.(item.target);
                        else focusTocTarget(item.target);
                        requestScrollUIUpdate();
                    }, 0);
                });
                return link;
            };

            const fillTocNav = (nav) => {
                if (!nav) return;
                const fragment = document.createDocumentFragment();

                tocItems.forEach(item => {
                    if (item.level === 'comments') {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'mt-3 pt-3 border-t border-dashed border-gray-200 dark:border-gray-800';
                        wrapper.appendChild(createTocLink(item, nav));
                        fragment.appendChild(wrapper);
                    } else {
                        fragment.appendChild(createTocLink(item, nav));
                    }
                });

                nav.replaceChildren(fragment);
            };

            fillTocNav(tocNav);
            fillTocNav(drawerTocNav);

            // Select by reading position in both scroll directions, including long sections.
            const siteHeader = document.querySelector('.zen-site-header');
            let activeItem;
            const setTocCurrent = (item, current) => {
                for (const link of [item?.desktopLink, item?.drawerLink]) {
                    if (!link) continue;
                    link.classList.toggle('active', current);
                    if (current) link.setAttribute('aria-current', 'location');
                    else link.removeAttribute('aria-current');
                }
            };
            updateToc = () => {
                const anchorOffset = parseFloat(getComputedStyle(tocItems[0].target).scrollMarginTop) || 0;
                const scrollPadding = parseFloat(getComputedStyle(document.documentElement).scrollPaddingTop) || 0;
                const readingTop = Math.max((siteHeader ? siteHeader.getBoundingClientRect().bottom : 80) + 24, anchorOffset + scrollPadding) + 1;
                let active;
                for (const item of tocItems) {
                    const rect = item.target.getClientRects()[0];
                    if (!rect) continue;
                    if (!active || rect.top <= readingTop) active = item;
                    else break;
                }
                if (active === activeItem) return;
                setTocCurrent(activeItem, false);
                activeItem = active;
                setTocCurrent(activeItem, true);
                if (active?.desktopLink && tocContainer?.getClientRects().length) {
                    const bounds = tocContainer.getBoundingClientRect();
                    const rect = active.desktopLink.getBoundingClientRect();
                    if (rect.top < bounds.top || rect.bottom > bounds.bottom) {
                        tocContainer.scrollTop += rect.top - bounds.top - bounds.height / 2;
                    }
                }
            };
            article.addEventListener('toggle', (event) => {
                if (event.target.tagName === 'DETAILS') requestScrollUIUpdate();
            }, true);

        } else {
            // 没有标题，隐藏容器
            if (tocContainer) tocContainer.style.display = 'none';
            if (headerTocBtn) {
                headerTocBtn.classList.remove('zen-toc-pending');
                headerTocBtn.hidden = true;
            }
        }
    }

    if (progressBar || backToTopBtn || updateToc) {
        window.addEventListener('scroll', requestScrollUIUpdate, { passive: true });
        window.addEventListener('resize', requestScrollUIUpdate);
        window.addEventListener('load', requestScrollUIUpdate, { once: true });
        updateScrollUI();
        if (updateToc) requestScrollUIUpdate();
    }

    // --- 4. Drawer TOC Interaction (A11y: Focus Trap) ---
    const drawerToc = document.getElementById('drawer-toc');
    const tocOverlay = document.getElementById('toc-overlay');
    const drawerClose = document.getElementById('drawer-toc-close');

    let drawerCloseTimer;
    let drawerNavigationTarget;
    let drawerOpening = 0;

    if (headerTocBtn && drawerToc && tocOverlay) {
        const openDrawer = () => {
            if ((activeModal && (activeModal.container !== drawerToc || !drawerCloseTimer)) || coreLightboxOpen()) return;
            const reopening = Boolean(drawerCloseTimer);
            if (drawerCloseTimer) {
                clearTimeout(drawerCloseTimer);
                drawerCloseTimer = null;
            }
            drawerNavigationTarget = null;
            tocOverlay.classList.remove('hidden');
            const opening = ++drawerOpening;
            requestAnimationFrame(() => {
                if (opening !== drawerOpening) return;
                tocOverlay.classList.remove('opacity-0');
                drawerToc.classList.remove('translate-x-full');
            });
            // A11y
            headerTocBtn.setAttribute('aria-expanded', 'true');
            drawerToc.removeAttribute('inert');
            if (reopening) focusModal(drawerToc, drawerClose);
            else lockModal(drawerToc, closeDrawer, drawerClose, tocOverlay);
        };

        closeDrawer = (navigationTarget = null) => {
            if (activeModal?.container !== drawerToc) return;
            if (navigationTarget) drawerNavigationTarget = navigationTarget;
            if (drawerCloseTimer) return;
            ++drawerOpening;
            tocOverlay.classList.add('opacity-0');
            drawerToc.classList.add('translate-x-full');

            drawerCloseTimer = setTimeout(() => {
                tocOverlay.classList.add('hidden');
                // A11y Cleanup
                headerTocBtn.setAttribute('aria-expanded', 'false');
                drawerToc.setAttribute('inert', '');
                drawerCloseTimer = null;
                unlockModal();
                if (drawerNavigationTarget) focusTocTarget(drawerNavigationTarget);
                else restoreModalFocus(headerTocBtn);
                drawerNavigationTarget = null;
            }, 300);
        };

        headerTocBtn.addEventListener('click', openDrawer);
        if (drawerClose) drawerClose.addEventListener('click', () => closeDrawer());
        tocOverlay.addEventListener('click', () => closeDrawer());

    }

    // --- Audio Player ---
    const audioElements = document.querySelectorAll('audio');
    audioElements.forEach(audio => {
        if (audio.error || audio.closest('.zen-audio-player, .wp-audio-shortcode, .mejs-container, .mejs-mediaelement')) return;
        // The condensed Zen UI has no volume control. Preserve it natively when needed.
        if (audio.defaultMuted || audio.muted || audio.volume === 0) {
            audio.controls = true;
            return;
        }
        const player = document.createElement('div');
        player.className = 'zen-audio-player';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'zen-audio-btn';

        let buttonPlaying;
        const setAudioButtonState = (playing) => {
            if (buttonPlaying === playing) return;
            buttonPlaying = playing;
            btn.innerHTML = `<i class="ph ph-${playing ? 'pause' : 'play'} text-lg" aria-hidden="true"></i>`;
            btn.setAttribute('aria-label', playing ? '暂停音频' : '播放音频');
        };

        setAudioButtonState(false);

        const progressContainer = document.createElement('div');
        progressContainer.className = 'zen-audio-progress-container';
        progressContainer.setAttribute('role', 'slider');
        progressContainer.setAttribute('tabindex', '0');
        progressContainer.setAttribute('aria-valuenow', '0');
        progressContainer.setAttribute('aria-valuemin', '0');
        progressContainer.setAttribute('aria-valuemax', '100');
        progressContainer.setAttribute('aria-label', '音频播放进度');
        progressContainer.setAttribute('aria-valuetext', '0%');

        const progressBar = document.createElement('div');
        progressBar.className = 'zen-audio-progress-bar';
        progressContainer.appendChild(progressBar);

        const timeDisplay = document.createElement('div');
        timeDisplay.className = 'zen-audio-time';
        timeDisplay.innerText = '00:00 / --:--';
        timeDisplay.setAttribute('aria-hidden', 'true');

        player.appendChild(btn);
        player.appendChild(progressContainer);
        player.appendChild(timeDisplay);
        audio.parentNode.insertBefore(player, audio.nextSibling);
        audio.setAttribute('controls', 'controls');
        audio.style.display = 'none';

        const formatAudioTime = (seconds) => {
            if (!Number.isFinite(seconds) || seconds < 0) return '--:--';
            const minutes = Math.floor(seconds / 60);
            const remainingSeconds = Math.floor(seconds % 60);
            return `${minutes}:${remainingSeconds < 10 ? '0' : ''}${remainingSeconds}`;
        };

        const updateAudioUI = () => {
            const hasDuration = Number.isFinite(audio.duration) && audio.duration > 0;
            const percent = hasDuration ? (audio.currentTime / audio.duration) * 100 : 0;
            progressBar.style.width = percent + '%';
            progressContainer.setAttribute('aria-valuenow', Math.round(percent));
            progressContainer.setAttribute('aria-valuetext', Math.round(percent) + '%');
            timeDisplay.innerText = `${formatAudioTime(audio.currentTime)} / ${formatAudioTime(audio.duration)}`;
        };

        let playAttempt = 0;
        const useNativeAudio = () => {
            ++playAttempt;
            audio.removeEventListener('play', syncAudioButton);
            audio.removeEventListener('pause', onAudioPause);
            audio.removeEventListener('error', useNativeAudio);
            audio.removeEventListener('timeupdate', updateAudioUI);
            audio.removeEventListener('loadedmetadata', updateAudioUI);
            audio.removeEventListener('durationchange', updateAudioUI);
            audio.removeEventListener('ended', onAudioEnded);
            audio.removeEventListener('volumechange', onAudioVolumeChange);
            const restoreFocus = player.contains(document.activeElement);
            audio.style.display = '';
            player.remove();
            if (restoreFocus) audio.focus({ preventScroll: true });
        };
        btn.addEventListener('click', () => {
            const attempt = ++playAttempt;
            if (audio.paused) {
                audio.play().then(() => {
                    if (attempt === playAttempt) syncAudioButton();
                }).catch((error) => {
                    if (attempt !== playAttempt || error?.name === 'AbortError') return;
                    useNativeAudio();
                });
            } else {
                audio.pause();
                setAudioButtonState(false);
            }
        });

        const syncAudioButton = () => {
            setAudioButtonState(!audio.paused && !audio.ended);
        };

        const onAudioPause = () => {
            if (audio.paused) ++playAttempt;
            syncAudioButton();
        };
        const onAudioEnded = () => {
            setAudioButtonState(false);
            updateAudioUI();
        };
        const onAudioVolumeChange = () => {
            if (audio.muted || audio.volume === 0) useNativeAudio();
        };

        audio.addEventListener('play', syncAudioButton);
        audio.addEventListener('pause', onAudioPause);
        audio.addEventListener('error', useNativeAudio);
        audio.addEventListener('timeupdate', updateAudioUI);
        audio.addEventListener('loadedmetadata', updateAudioUI);
        audio.addEventListener('durationchange', updateAudioUI);
        audio.addEventListener('ended', onAudioEnded);
        audio.addEventListener('volumechange', onAudioVolumeChange);

        progressContainer.addEventListener('click', (e) => {
            const rect = progressContainer.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
            const ratio = Math.min(1, Math.max(0, clickX / rect.width));
            audio.currentTime = (getComputedStyle(progressContainer).direction === 'rtl' ? 1 - ratio : ratio) * audio.duration;
        });

        progressContainer.addEventListener('keydown', (e) => {
            if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;

            const step = Math.max(audio.duration * 0.05, 5);
            if (e.key === 'ArrowLeft' || e.key === 'ArrowDown') {
                e.preventDefault();
                audio.currentTime = Math.max(0, audio.currentTime - step);
            } else if (e.key === 'ArrowRight' || e.key === 'ArrowUp') {
                e.preventDefault();
                audio.currentTime = Math.min(audio.duration, audio.currentTime + step);
            } else if (e.key === 'Home') {
                e.preventDefault();
                audio.currentTime = 0;
            } else if (e.key === 'End') {
                e.preventDefault();
                audio.currentTime = audio.duration;
            }
        });

        updateAudioUI();
        syncAudioButton();
    });

    // --- 7. Back to Top ---
    if (backToTopBtn) {
        backToTopBtn.addEventListener('click', (event) => {
            if (event.detail === 0) document.querySelector('.skip-link[href="#main-content"]')?.focus({ preventScroll: true });
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }

    // --- 8. Search ---
    const searchModal = document.getElementById('search-modal');
    const searchClose = document.getElementById('search-close');
    const searchInput = document.getElementById('search-input');
    if (searchToggle && searchModal && searchInput) {
        let lastActiveElementBeforeSearch;
        let searchCloseTimer;
        let searchOpening = 0;
        const openSearch = (trigger) => {
            if ((activeModal && (activeModal.container !== searchModal || !searchCloseTimer)) || coreLightboxOpen()) return false;
            const reopening = Boolean(searchCloseTimer);
            if (reopening) {
                clearTimeout(searchCloseTimer);
                searchCloseTimer = null;
            } else {
                lastActiveElementBeforeSearch = trigger;
            }
            searchModal.classList.remove('hidden');
            const opening = ++searchOpening;
            requestAnimationFrame(() => {
                if (opening !== searchOpening) return;
                searchModal.classList.remove('opacity-0');
                searchModal.classList.add('is-open');
            });
            searchToggle.setAttribute('aria-expanded', 'true');
            if (reopening) focusModal(searchModal, searchInput);
            else lockModal(searchModal, closeSearch, searchInput);
            return true;
        };

        const closeSearch = () => {
            if (activeModal?.container !== searchModal || searchCloseTimer) return;
            ++searchOpening;
            searchModal.classList.remove('is-open');
            searchModal.classList.add('opacity-0');
            searchCloseTimer = setTimeout(() => {
                searchModal.classList.add('hidden');
                searchCloseTimer = null;
                unlockModal();
                restoreModalFocus(lastActiveElementBeforeSearch);
            }, 320);
            searchToggle.setAttribute('aria-expanded', 'false');
        };

        const searchReturnTarget = () => document.activeElement === document.body ? searchToggle : document.activeElement;
        searchToggle.addEventListener('click', (event) => openSearch(event.detail > 0 ? searchToggle : searchReturnTarget()));
        searchClose?.addEventListener('click', closeSearch);
        searchModal.addEventListener('click', (e) => {
            if (e.target === searchModal || e.target.matches('.zen-search-shell') || e.target.closest('[data-search-dismiss="true"]')) closeSearch();
        });

        if (zenSearchShortcut) searchShortcutHandler = (event) => {
            if (!(event.metaKey || event.ctrlKey) || event.key !== 'k' || event.altKey || event.repeat) return;
            if (activeModal?.container === searchModal && !searchCloseTimer) {
                event.preventDefault();
                closeSearch();
            } else if (openSearch(searchReturnTarget())) {
                event.preventDefault();
            }
        };
        searchModal.classList.add('zen-search-modal', 'hidden', 'opacity-0');
        searchModal.setAttribute('role', 'dialog');
        searchModal.setAttribute('aria-modal', 'true');
        searchModal.setAttribute('aria-label', '全站搜索');
        if (searchClose) searchClose.hidden = false;
        searchModal.hidden = false;
        searchToggle.hidden = false;
        const searchFallback = document.getElementById('search-fallback');
        if (searchFallback) searchFallback.hidden = true;
    }

    // Optional enhancement runs only after every basic control has its listeners.
    const enhanceCodeBlocks = () => {
        const highlightMargin = 200;
        const pendingHighlights = new Set();
        let highlightObserver;
        codeBlocks.forEach(({ block, code }) => {
            if (code.dataset.highlighted) return;
            // Keep every bundled language/alias available, but bound unlabelled autodetection.
            // Explicit lang-/language- labels and Highlight.js legacy language classes opt in.
            const classes = code.className + ' ' + block.className;
            const explicitLanguage = /\blang(?:uage)?-([\w-]+)\b/i.test(classes) ||
                classes.split(/\s+/).some(name => hljs.getLanguage(name));
            if (!explicitLanguage) {
                const text = code.textContent;
                if (text.length > 8192 || text.split('\n', 201).length > 200) return;
            }
            const rect = block.getBoundingClientRect();
            if (typeof IntersectionObserver === 'undefined' ||
                (block.getClientRects().length && rect.top <= window.innerHeight + highlightMargin && rect.bottom >= -highlightMargin)) {
                hljs.highlightElement(code);
            } else {
                if (!highlightObserver) {
                    highlightObserver = new IntersectionObserver(entries => {
                        entries.forEach(entry => {
                            if (!entry.isIntersecting || !pendingHighlights.delete(entry.target)) return;
                            highlightObserver.unobserve(entry.target);
                            if (!entry.target.dataset.highlighted) hljs.highlightElement(entry.target);
                        });
                        if (!pendingHighlights.size) highlightObserver.disconnect();
                    }, { rootMargin: `${highlightMargin}px 0px` });
                }
                pendingHighlights.add(code);
                highlightObserver.observe(code);
            }
        });
    };
    if (!codeBlocks.length) return;
    if (typeof hljs !== 'undefined') {
        enhanceCodeBlocks();
    } else if (document.readyState === 'loading' || (document.readyState !== 'complete' && document.getElementById('highlight-js-js'))) {
        // The async script tag follows main in wp_footer; capture its non-bubbling load.
        const stopWatching = () => {
            document.removeEventListener('load', onHighlighterResult, true);
            document.removeEventListener('error', onHighlighterResult, true);
            window.removeEventListener('load', stopWatching);
        };
        const onHighlighterResult = (event) => {
            if (event.target.id !== 'highlight-js-js') return;
            stopWatching();
            if (event.type === 'load' && typeof hljs !== 'undefined') enhanceCodeBlocks();
        };
        document.addEventListener('load', onHighlighterResult, true);
        document.addEventListener('error', onHighlighterResult, true);
        window.addEventListener('load', stopWatching, { once: true });
    }
};

// Enqueued synchronously in wp_footer: all interactive markup already exists.
// Retain DOM-ready support for integrations that deliberately move the script earlier.
if (document.readyState === 'loading' && !document.body?.contains(document.currentScript)) {
    document.addEventListener('DOMContentLoaded', zenInit, { once: true });
} else {
    zenInit();
}
