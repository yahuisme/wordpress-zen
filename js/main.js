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
    let themeMode = 'auto';

    const getStoredThemeMode = () => {
        try {
            const storedMode = window.localStorage.getItem(themeStorageKey);
            return themeModes.includes(storedMode) ? storedMode : 'auto';
        } catch (error) {
            return 'auto';
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

    // --- 1. 代码高亮 ---
    const wpCodeBlocks = document.querySelectorAll('pre.wp-block-code');
    wpCodeBlocks.forEach(block => {
        const code = block.querySelector('code');
        if (code) {
            block.classList.forEach(className => {
                if (className.startsWith('language-')) code.classList.add(className);
            });
        }
        if (typeof hljs !== 'undefined' && code) hljs.highlightElement(code);
    });

    // --- 2. Lightbox (A11y: Focus Management & ARIA) ---
    const lightbox = document.getElementById('lightbox');
    const lightboxImg = document.getElementById('lightbox-img');
    const closeBtn = document.getElementById('lightbox-close');
    const images = document.querySelectorAll('.entry-content img, .wp-block-image img');
    let lastFocusedElement;

    images.forEach(img => {
        img.classList.add('cursor-zoom-in');
        img.setAttribute('tabindex', '0');
        img.setAttribute('role', 'button');
        img.setAttribute('aria-label', '点击查看大图');

        const openLightbox = (e) => {
            if (img.parentElement.tagName === 'A') return;
            e.preventDefault();
            lastFocusedElement = document.activeElement;

            lightboxImg.src = img.src;
            lightboxImg.alt = img.alt || '放大图片';
            if (img.srcset) lightboxImg.srcset = img.srcset;
            if (lightbox) {
                lightbox.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
                setTimeout(() => closeBtn.focus(), 100);
            }
        };

        img.addEventListener('click', openLightbox);
        img.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === ' ') {
                openLightbox(e);
            }
        });
    });

    const closeLightbox = () => {
        if (lightbox && !lightbox.classList.contains('hidden')) {
            lightbox.classList.add('hidden');
            document.body.style.overflow = 'auto';
            if (lastFocusedElement) lastFocusedElement.focus();
        }
    };

    if (closeBtn) closeBtn.addEventListener('click', closeLightbox);
    if (lightbox) lightbox.addEventListener('click', (e) => {
        if (e.target === lightbox) closeLightbox();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') closeLightbox();
    });

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

    window.addEventListener('scroll', requestScrollUIUpdate, { passive: true });
    updateScrollUI();

    // TOC Generation & Spy
    if (article) {
        const headers = article.querySelectorAll('h2, h3');
        if (headers.length > 0) {
            // 1. 初始化显示
            // 只要有目录内容，就移除 display:none (hidden)
            // 具体的显隐由 CSS @media 查询控制 (xl:block / xl:hidden)
            if (tocContainer) tocContainer.classList.remove('opacity-0');
            if (floatingTocBtn) floatingTocBtn.classList.remove('hidden');

            const tocItems = Array.from(headers).map((header, index) => {
                if (!header.id) header.id = 'section-' + index;
                return {
                    id: header.id,
                    level: header.tagName.toLowerCase(),
                    text: header.textContent.trim() || `章节 ${index + 1}`,
                };
            });

            if (document.getElementById('comments')) {
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
                                    activeLink.scrollIntoView({ block: 'center', behavior: 'smooth' });
                                }
                            }
                        });
                    }
                });
            }, { rootMargin: '-100px 0px -70% 0px' });

            headers.forEach(header => observer.observe(header));
            const commentsSection = document.getElementById('comments');
            if (commentsSection) observer.observe(commentsSection);

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

    if (floatingTocBtn && drawerToc && tocOverlay) {

        const openDrawer = () => {
            lastFocusBeforeDrawer = document.activeElement;
            tocOverlay.classList.remove('hidden');
            requestAnimationFrame(() => {
                tocOverlay.classList.remove('opacity-0');
                drawerToc.classList.remove('translate-x-full');
            });
            document.body.style.overflow = 'hidden';

            // A11y
            floatingTocBtn.setAttribute('aria-expanded', 'true');
            drawerToc.removeAttribute('inert');

            // Focus trap: move focus to close button
            setTimeout(() => {
                if (drawerClose) drawerClose.focus();
            }, 300);
        };

        const closeDrawer = () => {
            tocOverlay.classList.add('opacity-0');
            drawerToc.classList.add('translate-x-full');

            setTimeout(() => {
                tocOverlay.classList.add('hidden');
                document.body.style.overflow = '';
                // A11y Cleanup
                floatingTocBtn.setAttribute('aria-expanded', 'false');
                drawerToc.setAttribute('inert', '');
                if (lastFocusBeforeDrawer) lastFocusBeforeDrawer.focus();
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

        // Focus Trap Logic for Drawer
        drawerToc.addEventListener('keydown', (e) => {
            if (e.key === 'Tab') {
                const focusableElements = drawerToc.querySelectorAll('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                const firstElement = focusableElements[0];
                const lastElement = focusableElements[focusableElements.length - 1];

                if (e.shiftKey) { // Shift + Tab
                    if (document.activeElement === firstElement) {
                        e.preventDefault();
                        lastElement.focus();
                    }
                } else { // Tab
                    if (document.activeElement === lastElement) {
                        e.preventDefault();
                        firstElement.focus();
                    }
                }
            } else if (e.key === 'Escape') {
                closeDrawer();
            }
        });
    }

    // --- 5. Mobile Menu ---
    const mobileMenuBtn = document.getElementById('mobile-menu-btn');
    const mobileMenu = document.getElementById('mobile-menu');

    if (mobileMenuBtn && mobileMenu) {
        mobileMenuBtn.addEventListener('click', () => {
            const isHidden = mobileMenu.classList.contains('hidden');
            if (isHidden) {
                mobileMenu.classList.remove('hidden');
                mobileMenuBtn.setAttribute('aria-expanded', 'true');
                mobileMenuBtn.setAttribute('aria-label', '关闭菜单');
            } else {
                mobileMenu.classList.add('hidden');
                mobileMenuBtn.setAttribute('aria-expanded', 'false');
                mobileMenuBtn.setAttribute('aria-label', '打开菜单');
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
        btn.className = 'zen-audio-btn';
        btn.setAttribute('aria-label', '播放音频');
        btn.innerHTML = '<i class="ph ph-play text-lg" aria-hidden="true"></i>';

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
                audio.play();
                btn.innerHTML = '<i class="ph ph-pause text-lg" aria-hidden="true"></i>';
                btn.setAttribute('aria-label', '暂停音频');
            } else {
                audio.pause();
                btn.innerHTML = '<i class="ph ph-play text-lg" aria-hidden="true"></i>';
                btn.setAttribute('aria-label', '播放音频');
            }
        });

        audio.addEventListener('timeupdate', () => {
            updateAudioUI();
        });

        audio.addEventListener('loadedmetadata', updateAudioUI);

        progressContainer.addEventListener('click', (e) => {
            const rect = progressContainer.getBoundingClientRect();
            const clickX = e.clientX - rect.left;
            if (!Number.isFinite(audio.duration) || audio.duration <= 0) return;
            audio.currentTime = (clickX / rect.width) * audio.duration;
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
            btn.innerHTML = '<i class="ph ph-play text-lg" aria-hidden="true"></i>';
            btn.setAttribute('aria-label', '播放音频');
            updateAudioUI();
        });
    });

    // --- 7. Back to Top ---
    if (backToTopBtn) {
        backToTopBtn.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    // --- 8. Search ---
    const searchToggle = document.getElementById('search-toggle');
    const searchModal = document.getElementById('search-modal');
    const searchClose = document.getElementById('search-close');
    const searchInput = document.getElementById('search-input');
    let lastActiveElementBeforeSearch;
    let searchCloseTimer;

    if (searchToggle && searchModal && searchInput) {
        const openSearch = () => {
            if (searchCloseTimer) clearTimeout(searchCloseTimer);
            lastActiveElementBeforeSearch = document.activeElement;
            searchModal.classList.remove('hidden');
            requestAnimationFrame(() => {
                searchModal.classList.remove('opacity-0');
                searchModal.classList.add('is-open');
            });
            document.body.style.overflow = 'hidden';
            searchToggle.setAttribute('aria-expanded', 'true');
            setTimeout(() => searchInput.focus(), 180);
        };

        const closeSearch = () => {
            if (searchModal.classList.contains('hidden')) return;
            if (searchCloseTimer) clearTimeout(searchCloseTimer);
            searchModal.classList.remove('is-open');
            searchModal.classList.add('opacity-0');
            searchCloseTimer = setTimeout(() => {
                searchModal.classList.add('hidden');
                document.body.style.overflow = '';
                if (lastActiveElementBeforeSearch) lastActiveElementBeforeSearch.focus();
            }, 320);
            searchToggle.setAttribute('aria-expanded', 'false');
        };

        searchToggle.addEventListener('click', openSearch);
        if (searchClose) searchClose.addEventListener('click', closeSearch);
        searchModal.addEventListener('click', (e) => {
            if (e.target === searchModal || e.target.closest('[data-search-dismiss="true"]')) closeSearch();
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && !searchModal.classList.contains('hidden')) {
                closeSearch();
            }
            if ((e.metaKey || e.ctrlKey) && e.key === 'k') {
                e.preventDefault();
                if (searchModal.classList.contains('hidden')) openSearch(); else closeSearch();
            }
        });
    }
});
