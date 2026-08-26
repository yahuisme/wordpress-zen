document.addEventListener('DOMContentLoaded', () => {

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
    const zenSearchShortcut = !!(window.zenSettings && window.zenSettings.search_shortcut);
    const reduceMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
    const reduceMotion = reduceMotionQuery ? reduceMotionQuery.matches : false;

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
    }

    const trapFocus = (container) => {
        container.addEventListener('keydown', (event) => {
            if (event.key !== 'Tab') return;

            const focusable = Array.from(container.querySelectorAll('button:not([disabled]), [href], input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'));
            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (!first || !last) {
                event.preventDefault();
            } else if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        });
    };

    // --- 1. 代码高亮 + 一键复制 ---
    const copyTextToClipboard = (text) => {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise((resolve, reject) => {
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
            }
        });
    };

    const wpCodeBlocks = document.querySelectorAll('.entry-content pre, pre.wp-block-code');
    wpCodeBlocks.forEach(block => {
        const code = block.querySelector('code');
        if (code) {
            block.classList.forEach(className => {
                if (className.startsWith('language-')) code.classList.add(className);
            });
        }
        if (typeof hljs !== 'undefined' && code && !code.dataset.highlighted) hljs.highlightElement(code);

        if (code && !block.querySelector('.zen-code-copy')) {
            block.classList.add('zen-code-block');
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

            copyBtn.addEventListener('click', () => {
                copyTextToClipboard(code.textContent).then(() => {
                    copyIcon.className = 'ph ph-check';
                    copyLabel.textContent = '已复制';
                    copyBtn.classList.add('is-copied');
                    copyBtn.setAttribute('aria-label', '已复制');
                    setTimeout(() => {
                        copyIcon.className = 'ph ph-copy';
                        copyLabel.textContent = '复制';
                        copyBtn.classList.remove('is-copied');
                        copyBtn.setAttribute('aria-label', '复制代码');
                    }, 1600);
                }).catch(() => {
                    copyLabel.textContent = '复制失败';
                    copyBtn.setAttribute('aria-label', '复制失败');
                    setTimeout(() => {
                        copyLabel.textContent = '复制';
                        copyBtn.setAttribute('aria-label', '复制代码');
                    }, 1600);
                });
            });

            block.appendChild(copyBtn);
        }
    });

    // --- 2. Lightbox (A11y: Focus Management & ARIA) ---
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');

    if (lightbox && lightboxImg) {
        const closeBtn = document.getElementById('lightbox-close');
        const images = document.querySelectorAll('.entry-content img, .wp-block-image img');
        let lastFocusedElement;
        let lightboxFocusTimer;
        trapFocus(lightbox);

        images.forEach(img => {
            if (img.closest('a')) return;
            img.classList.add('cursor-zoom-in');
            img.setAttribute('tabindex', '0');
            img.setAttribute('role', 'button');
            img.setAttribute('aria-label', '点击查看大图');

            const openLightbox = (e) => {
                if (img.parentElement.tagName === 'A') return;
                e.preventDefault();
                lastFocusedElement = document.activeElement;

                lightboxImg.src = img.currentSrc || img.src;
                lightboxImg.alt = img.alt || '放大图片';
                if (img.srcset) {
                    lightboxImg.srcset = img.srcset;
                    lightboxImg.sizes = img.sizes || '92vw';
                } else {
                    lightboxImg.removeAttribute('srcset');
                    lightboxImg.removeAttribute('sizes');
                }
                lightbox.classList.remove('hidden');
                setBackgroundModalState();
                clearTimeout(lightboxFocusTimer);
                lightboxFocusTimer = setTimeout(() => {
                    if (!lightbox.classList.contains('hidden') && closeBtn) closeBtn.focus();
                }, 100);
            };

            img.addEventListener('click', openLightbox);
            img.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    openLightbox(e);
                }
            });
        });

        const closeLightbox = () => {
            if (!lightbox.classList.contains('hidden')) {
                lightbox.classList.add('hidden');
                clearTimeout(lightboxFocusTimer);
                setBackgroundModalState();
                if (lastFocusedElement) lastFocusedElement.focus();
            }
        };

        if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
        lightbox.addEventListener('click', (e) => {
            if (e.target === lightbox) closeLightbox();
        });
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') closeLightbox();
        });
    }

    // --- 3. TOC 目录 (Universal Logic) ---
    const article = document.getElementById('post-content');
    const tocContainer = document.getElementById('toc-container'); // PC Sidebar
    const tocNav = document.getElementById('toc-nav'); // PC Nav Content
    const drawerTocNav = document.getElementById('drawer-toc-nav'); // Drawer Nav Content
    const floatingTocBtn = document.getElementById('floating-toc-btn'); // Floating Trigger

    const progressBar = document.getElementById('reading-progress');
    const backToTopBtn = document.getElementById('back-to-top');
    let scrollTicking = false;

    const updateScrollUI = () => {
        if (progressBar) {
            const scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
            const scrollHeight = document.documentElement.scrollHeight - document.documentElement.clientHeight;
            const scrollPercent = scrollHeight > 0 ? (scrollTop / scrollHeight) * 100 : 0;
            progressBar.style.width = scrollPercent + '%';
        }

        if (backToTopBtn) {
            if (window.scrollY > 300) {
                backToTopBtn.classList.remove('opacity-0', 'pointer-events-none', 'translate-y-4');
            } else {
                backToTopBtn.classList.add('opacity-0', 'pointer-events-none', 'translate-y-4');
            }
        }

        scrollTicking = false;
    };

    const requestScrollUIUpdate = () => {
        if (!scrollTicking) {
            scrollTicking = true;
            requestAnimationFrame(updateScrollUI);
        }
    };

    if (progressBar || backToTopBtn) {
        window.addEventListener('scroll', requestScrollUIUpdate, { passive: true });
        updateScrollUI();
    }

    // TOC Generation & Spy
    if (article) {
        const headers = article.querySelectorAll('h2, h3');
        if (headers.length > 0) {
            // 1. 初始化显示
            // 只要有目录内容，就移除 display:none (hidden)
            // 具体的显隐由 CSS @media 查询控制 (xl:block / xl:hidden)
            if (tocContainer) tocContainer.classList.remove('opacity-0');
            if (floatingTocBtn) floatingTocBtn.classList.remove('hidden');

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
                    level: header.tagName.toLowerCase(),
                    text: header.textContent.trim() || `章节 ${index + 1}`,
                };
            });

            if (document.getElementById('comments') && !tocItems.some(item => item.id === 'comments')) {
                tocItems.push({ id: 'comments', level: 'comments', text: '评论区' });
            }

            const createTocLink = (item) => {
                const link = document.createElement('a');
                const paddingClass = item.level === 'h3' ? 'pl-6 text-xs' : 'pl-3 text-sm';
                link.href = '#' + item.id;
                link.className = `toc-link ${paddingClass}`;
                link.dataset.target = item.id;
                link.textContent = item.text;
                return link;
            };

            const fillTocNav = (nav) => {
                if (!nav) return;
                const fragment = document.createDocumentFragment();

                tocItems.forEach(item => {
                    if (item.level === 'comments') {
                        const wrapper = document.createElement('div');
                        wrapper.className = 'mt-3 pt-3 border-t border-dashed border-gray-200 dark:border-gray-800';
                        wrapper.appendChild(createTocLink(item));
                        fragment.appendChild(wrapper);
                    } else {
                        fragment.appendChild(createTocLink(item));
                    }
                });

                nav.replaceChildren(fragment);
            };

            fillTocNav(tocNav);
            fillTocNav(drawerTocNav);

            // 4. Scroll Spy (滚动监听)
            if (typeof IntersectionObserver === 'undefined') {
                if (tocContainer) tocContainer.classList.remove('opacity-0');
            } else {
            const observer = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        // 清除所有高亮
                        document.querySelectorAll('.toc-link').forEach(link => {
                            link.classList.remove('active');
                            link.removeAttribute('aria-current');
                        });

                        // 高亮对应 ID 的所有链接 (Sidebar 和 Drawer 同时高亮)
                        const activeLinks = Array.from(document.querySelectorAll('.toc-link')).filter(link => link.dataset.target === entry.target.id);
                        activeLinks.forEach(activeLink => {
                            activeLink.classList.add('active');
                            activeLink.setAttribute('aria-current', 'location');

                            // 仅对 PC 侧边栏进行自动滚动定位，防止在 Drawer 打开时造成页面跳动
                            if (tocNav && activeLink.parentElement === tocNav) {
                                const navRect = tocNav.getBoundingClientRect();
                                const linkRect = activeLink.getBoundingClientRect();
                                if (linkRect.bottom > navRect.bottom || linkRect.top < navRect.top) {
                                    activeLink.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
                                }
                            }
                        });
                    }
                });
            }, { rootMargin: '-100px 0px -70% 0px' });

            headers.forEach(header => observer.observe(header));
            const commentsSection = document.getElementById('comments');
            if (commentsSection) observer.observe(commentsSection);
            }

        } else {
            // 没有标题，隐藏容器
            if (tocContainer) tocContainer.style.display = 'none';
            if (floatingTocBtn) floatingTocBtn.style.display = 'none';
        }
    }

    // --- 4. Drawer TOC Interaction (A11y: Focus Trap) ---
    const drawerToc = document.getElementById('drawer-toc');
    const tocOverlay = document.getElementById('toc-overlay');
    const drawerClose = document.getElementById('drawer-toc-close');
    let lastFocusBeforeDrawer;
    let drawerCloseTimer;

    if (floatingTocBtn && drawerToc && tocOverlay) {
        trapFocus(drawerToc);

        const openDrawer = () => {
            if (!drawerToc.hasAttribute('inert')) return;
            if (drawerCloseTimer) {
                clearTimeout(drawerCloseTimer);
                drawerCloseTimer = null;
            }
            lastFocusBeforeDrawer = document.activeElement;
            tocOverlay.classList.remove('hidden');
            requestAnimationFrame(() => {
                tocOverlay.classList.remove('opacity-0');
                drawerToc.classList.remove('translate-x-full');
            });
            // A11y
            floatingTocBtn.setAttribute('aria-expanded', 'true');
            drawerToc.removeAttribute('inert');
            setBackgroundModalState();

            // Focus trap: move focus to close button
            setTimeout(() => {
                if (drawerClose) drawerClose.focus();
            }, 300);
        };

        const closeDrawer = () => {
            if (drawerToc.hasAttribute('inert') || drawerCloseTimer) return;
            tocOverlay.classList.add('opacity-0');
            drawerToc.classList.add('translate-x-full');

            drawerCloseTimer = setTimeout(() => {
                tocOverlay.classList.add('hidden');
                // A11y Cleanup
                floatingTocBtn.setAttribute('aria-expanded', 'false');
                drawerToc.setAttribute('inert', '');
                drawerToc.setAttribute('data-zen-original-inert', 'true');
                drawerToc.setAttribute('data-zen-modal-inert', 'true');
                setBackgroundModalState();
                const anotherModalOpen = document.querySelector('#lightbox:not(.hidden), #search-modal:not(.hidden)');
                if (!anotherModalOpen && lastFocusBeforeDrawer) lastFocusBeforeDrawer.focus();
                drawerCloseTimer = null;
            }, 300);
        };

        floatingTocBtn.addEventListener('click', openDrawer);
        if (drawerClose) drawerClose.addEventListener('click', closeDrawer);
        tocOverlay.addEventListener('click', closeDrawer);

        // 点击目录链接跳转后，自动关闭抽屉
        if (drawerTocNav) {
            drawerTocNav.addEventListener('click', (e) => {
                if (e.target.closest('a')) {
                    closeDrawer();
                }
            });
        }

        drawerToc.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                closeDrawer();
            }
        });
    }

    // --- 5. Mobile Menu ---
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');

    if (mobileMenuBtn && mobileMenu) {
        const closeMobileMenu = (restoreFocus = true) => {
            mobileMenu.classList.add('hidden');
            mobileMenu.setAttribute('aria-hidden', 'true');
            mobileMenuBtn.setAttribute('aria-expanded', 'false');
            mobileMenuBtn.setAttribute('aria-label', '打开菜单');
            if (restoreFocus) mobileMenuBtn.focus();
        };

        mobileMenuBtn.addEventListener('click', () => {
            const isHidden = mobileMenu.classList.contains('hidden');
            if (isHidden) {
                if (drawerToc && !drawerToc.hasAttribute('inert')) return;
                mobileMenu.classList.remove('hidden');
                mobileMenu.removeAttribute('aria-hidden');
                mobileMenuBtn.setAttribute('aria-expanded', 'true');
                mobileMenuBtn.setAttribute('aria-label', '关闭菜单');
                const firstLink = mobileMenu.querySelector('a');
                if (firstLink) firstLink.focus();
            } else {
                closeMobileMenu();
            }
        });

        mobileMenu.addEventListener('click', (event) => {
            if (event.target.closest('a')) closeMobileMenu(false);
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && !mobileMenu.classList.contains('hidden')) {
                closeMobileMenu();
            }
        });
    }

    // --- 6. Audio Player ---
    const audioElements = document.querySelectorAll('audio');
    audioElements.forEach(audio => {
        if (audio.closest('.zen-audio-player')) return;
        const player = document.createElement('div');
        player.className = 'zen-audio-player';

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'zen-audio-btn';
        btn.setAttribute('aria-label', '播放音频');

        const setAudioButtonState = (playing) => {
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

        btn.addEventListener('click', () => {
            if (audio.paused) {
                audio.play().then(() => {
                    setAudioButtonState(true);
                }).catch(() => {
                    audio.style.display = '';
                    setAudioButtonState(false);
                });
            } else {
                audio.pause();
                setAudioButtonState(false);
            }
        });

        const syncAudioButton = () => {
            setAudioButtonState(!audio.paused && !audio.ended);
        };

        audio.addEventListener('play', syncAudioButton);
        audio.addEventListener('pause', syncAudioButton);
        audio.addEventListener('error', () => {
            audio.style.display = '';
            syncAudioButton();
        });
        audio.addEventListener('timeupdate', () => {
            updateAudioUI();
        });

        audio.addEventListener('loadedmetadata', updateAudioUI);

        progressContainer.addEventListener('click', (e) => {
            const rect = progressContainer.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
            const ratio = Math.min(1, Math.max(0, clickX / rect.width));
            audio.currentTime = ratio * audio.duration;
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

        audio.addEventListener('ended', () => {
            setAudioButtonState(false);
            updateAudioUI();
        });
        audio.style.display = 'none';
    });

    // --- 7. Back to Top ---
    if (backToTopBtn) {
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: reduceMotion ? 'auto' : 'smooth' });
        });
    }

    // --- 8. Search ---
    const searchToggle = document.getElementById('search-toggle');
    const searchModal = document.getElementById('search-modal');
    const searchClose = document.getElementById('search-close');
    const searchInput = document.getElementById('search-input');
    let lastActiveElementBeforeSearch;
    let searchCloseTimer;
    let searchFocusTimer;
    const setBackgroundModalState = () => {
        const applyInert = Boolean(document.querySelector('#lightbox:not(.hidden), #search-modal:not(.hidden), #drawer-toc:not([inert])'));
        const activeModalIds = ['lightbox', 'search-modal', 'drawer-toc'];
        const activeModals = activeModalIds.map((id) => document.getElementById(id)).filter((modal) => modal && (modal.id === 'drawer-toc' ? !modal.hasAttribute('inert') : !modal.classList.contains('hidden')));

        const isolateDescendants = (node) => {
            Array.from(node.children).forEach((child) => {
                if (activeModals.some((modal) => child === modal || child.contains(modal))) {
                    if (!activeModals.includes(child)) isolateDescendants(child);
                    return;
                }
                if (!child.hasAttribute('data-zen-modal-inert')) {
                    child.setAttribute('data-zen-original-inert', child.hasAttribute('inert') ? 'true' : 'false');
                    child.setAttribute('data-zen-original-aria-hidden', child.getAttribute('aria-hidden') || '');
                }
                child.setAttribute('inert', '');
                child.setAttribute('aria-hidden', 'true');
                child.setAttribute('data-zen-modal-inert', 'true');
            });
        };

        document.body.style.overflow = applyInert ? 'hidden' : '';

        Array.from(document.body.children).forEach((node) => {
            if (activeModals.includes(node)) {
                return;
            }

            if (applyInert) {
                if (activeModals.some((modal) => node.contains(modal))) {
                    isolateDescendants(node);
                } else {
                    if (!node.hasAttribute('data-zen-modal-inert')) {
                        node.setAttribute('data-zen-original-inert', node.hasAttribute('inert') ? 'true' : 'false');
                        node.setAttribute('data-zen-original-aria-hidden', node.getAttribute('aria-hidden') || '');
                    }
                    node.setAttribute('inert', '');
                    node.setAttribute('aria-hidden', 'true');
                    node.setAttribute('data-zen-modal-inert', 'true');
                }
            } else {
                if (node.getAttribute('data-zen-modal-inert') === 'true') {
                    if (node.getAttribute('data-zen-original-inert') === 'false') node.removeAttribute('inert');
                    if (node.getAttribute('data-zen-original-aria-hidden')) node.setAttribute('aria-hidden', node.getAttribute('data-zen-original-aria-hidden'));
                    else node.removeAttribute('aria-hidden');
                    node.removeAttribute('data-zen-modal-inert');
                    node.removeAttribute('data-zen-original-inert');
                    node.removeAttribute('data-zen-original-aria-hidden');
                }
                node.querySelectorAll('[data-zen-modal-inert="true"]').forEach((child) => {
                    if (child.getAttribute('data-zen-original-inert') === 'false') child.removeAttribute('inert');
                    if (child.getAttribute('data-zen-original-aria-hidden')) child.setAttribute('aria-hidden', child.getAttribute('data-zen-original-aria-hidden'));
                    else child.removeAttribute('aria-hidden');
                    child.removeAttribute('data-zen-modal-inert');
                    child.removeAttribute('data-zen-original-inert');
                    child.removeAttribute('data-zen-original-aria-hidden');
                });
            }
        });
    };

    if (searchToggle && searchModal && searchInput) {
        trapFocus(searchModal);
        const openSearch = () => {
            if (searchCloseTimer) clearTimeout(searchCloseTimer);
            lastActiveElementBeforeSearch = document.activeElement;
            searchModal.classList.remove('hidden');
            requestAnimationFrame(() => {
                searchModal.classList.remove('opacity-0');
                searchModal.classList.add('is-open');
            });
            setBackgroundModalState();
            searchToggle.setAttribute('aria-expanded', 'true');
            clearTimeout(searchFocusTimer);
            searchFocusTimer = setTimeout(() => {
                if (!searchModal.classList.contains('hidden')) searchInput.focus();
            }, 180);
        };

        const closeSearch = () => {
            if (searchModal.classList.contains('hidden')) return;
            if (searchCloseTimer) clearTimeout(searchCloseTimer);
            clearTimeout(searchFocusTimer);
            searchModal.classList.remove('is-open');
            searchModal.classList.add('opacity-0');
            searchCloseTimer = setTimeout(() => {
                searchModal.classList.add('hidden');
                setBackgroundModalState();
                const anotherModalOpen = document.querySelector('#lightbox:not(.hidden), #drawer-toc:not([inert])');
                if (!anotherModalOpen && lastActiveElementBeforeSearch) lastActiveElementBeforeSearch.focus();
            }, 320);
            searchToggle.setAttribute('aria-expanded', 'false');
        };

        searchToggle.addEventListener('click', () => {
            if (mobileMenu && !mobileMenu.classList.contains('hidden')) {
                mobileMenu.classList.add('hidden');
                if (mobileMenuBtn) {
                    mobileMenuBtn.setAttribute('aria-expanded', 'false');
                    mobileMenuBtn.setAttribute('aria-label', '打开菜单');
                }
            }
            openSearch();
        });
        if (searchClose) searchClose.addEventListener('click', closeSearch);
        searchModal.addEventListener('click', (e) => {
            if (e.target === searchModal || e.target.closest('[data-search-dismiss="true"]')) closeSearch();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !searchModal.classList.contains('hidden')) {
                closeSearch();
            }
            if (zenSearchShortcut && (e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (searchModal.classList.contains('hidden')) openSearch(); else closeSearch();
            }
        });
    }
});
