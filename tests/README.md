# 回归测试

## 发布包

```sh
python3 tests/package-regression.py
python3 tests/release-font-package-regression.py
```

使用独立 Git 索引打包当前工作区，不修改暂存区或创建提交；执行发布工作流的源码 ZIP 校验，覆盖显示名、版本、环境要求、文件清单及错误元数据拒绝。需要 Python 3.9+ 与 Git。

完整安装包再通过 `scripts/package-release.py TRACKED_ZIP FONT_SOURCE_ZIP OUTPUT_ZIP` 加入授权仓耳字体。`FONT_SOURCE_ZIP` 使用固定的 v2.0.0 Release `zen.zip`；脚本先校验其 SHA-256，再精确合并字体分包，保留字体版权说明并剔除已无使用者的代码字体别名。`release-font-package-regression.py` 使用明确的合成 ZIP 验证实际合并器、错误摘要／重复路径／越界路径拒绝，以及失败时保留原输出；不访问生产或下载网络资源。手动工作流执行相同合并器，不会自动发布 Release。

## PHP 行为

```sh
php tests/audit-closeout.php
php tests/php-regression.php
php tests/assets-regression.php
php tests/custom-code-regression.php
php tests/seo-regression.php
php tests/template-design-regression.php
php tests/toc-startup-regression.php
php tests/design-assets-regression.php
php tests/admin-options.php
php tests/reset-regression.php
php tests/archive-context-regression.php
php tests/archive-batching-regression.php
php tests/options-boundaries-regression.php
php tests/frontend-media-regression.php
php tests/content-text-regression.php
php -n tests/utf8-fallback-regression.php
php tests/reading-count-regression.php
php tests/reading-count-assets-regression.php
```

实体计长测试使用隔离 WordPress 源码，覆盖普通正文、转义代码和同步样板：

```sh
ZEN_WP_CORE=/path/to/isolated/wordpress php tests/reading-entity-regression.php
ZEN_WP_CORE=/path/to/isolated/wordpress php -n tests/reading-entity-regression.php
```

需要 PHP 8.0+。模板与目录首屏测试通过 `ZEN_WP_CORE`（维护环境默认为 `/opt/test-tools/wordpress/clean-core`）加载真实 Core HTML tokenizer；不连接数据库。其余使用明确的 WordPress 边界替身，覆盖分页、缓存钩子、搜索高亮、评论、友链目标及模板输出；不替代真实 WordPress 验收。

## JavaScript 行为

使用 Node.js 与仓库外的共享 `jsdom` 依赖，保留代码高亮、前台交互及后台设置测试。已验证 `jsdom` 26.1.0。所有命令从仓库根目录执行。

在维护环境中运行：

```sh
. /opt/test-tools/env.sh
node --test --test-concurrency=1 tests/highlight-regression.cjs tests/interaction-regression.cjs tests/admin-ui.cjs tests/search-lifecycle-regression.cjs tests/frontend-loading-regression.cjs tests/frontend-lifecycle-regression.cjs
```

其他环境需自行在仓库外安装 `jsdom`，并将 `NODE_PATH` 指向对应的 `node_modules` 目录。主题安装和运行不需要这些工具。

## 原生 WordPress

准备可销毁的 WordPress + SQLite 环境：

```sh
bash tests/ci-native-setup.sh
```

脚本会固定 WordPress 版本、安装 SQLite Database Integration、复制当前主题并创建 loopback 站点。默认随后运行原生回归（含 AJAX 浏览次数）；仅准备环境、只补测计数边界时使用：

```sh
ZEN_CI_RUNTIME="$TMPDIR/zen-native" bash tests/ci-native-setup.sh --prepare-only
ZEN_TEST_ISOLATED=1 php tests/native-reading-count-regression.php "$TMPDIR/zen-native/wp-load.php"
```

脚本不读取生产配置或数据库。默认创建唯一的临时目录并打印路径；指定 `ZEN_CI_RUNTIME` 时须使用父目录已存在、目标尚不存在的绝对路径。可选的 `WPCLI_BIN` 须指向已有、可读的 WP-CLI PHP 文件（绝对路径），脚本不会改写它。测试后由调用者清理此次创建的目录。

无需安装 WordPress 即可检查路径保护：`python3 tests/ci-native-setup-regression.py`。

```sh
ZEN_TEST_ISOLATED=1 php tests/native-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/php-native-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-content-text-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 python3 tests/native-counter-concurrency.py /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-counter-storage-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-counter-cache-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-excerpt-entity-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-comment-form-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-backend-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-admin-save-regression.php /path/to/isolated/wp-load.php malformed-footer
ZEN_TEST_ISOLATED=1 php tests/native-admin-save-regression.php /path/to/isolated/wp-load.php refused
ZEN_TEST_ISOLATED=1 php tests/native-directory-regression.php /path/to/isolated/wp-load.php
ZEN_TEST_ISOLATED=1 php tests/native-single-regression.php /path/to/isolated/wp-load.php IMAGE_ATTACHMENT_ID 1
ZEN_TEST_ISOLATED=1 php tests/native-single-regression.php /path/to/isolated/wp-load.php IMAGE_ATTACHMENT_ID 0
```

仅用于 `WP_ENVIRONMENT_TYPE=local`、站点地址为 `127.0.0.1` 的一次性安装，启用当前 Zen 主题。`native-regression.php` 会创建并删除自己的样板/文章、临时启用并恢复计数开关，覆盖同步样板、循环引用、可见性与爬虫过滤；必须看到 `NATIVE_COMPLETE` 且退出码为 0。

`php-native-regression.php` 覆盖待审评论原生边界、搜索实体、友链 rel 和分类关系缓存；可追加 `comments`、`search`、`links`、`category-cache` 仅运行相应部分，须看到 `PHP_NATIVE_COMPLETE` 且退出码为 0。隔离环境须拦截出站 HTTP 与邮件，测试数据及选项由脚本清理恢复。

`native-content-text-regression.php` 覆盖手写与自动摘要、结构化标题实体编码、密码文章高亮资源的锁定和解锁边界，须看到 `NATIVE_CONTENT_COMPLETE` 且退出码为 0。

`native-admin-save-regression.php` 经原生 `options.php` 验证保存结果与通知，可选 `refused`、`invalid`、`unchanged`、`create`、`full` 或 `malformed-footer`。最后一种验证非字符串页脚不会中断原生保存，旧文本被保留并显示字段错误；`native-backend-regression.php` 另只读验证注册的 sanitizer、KSES 及真实 Core 摘要清理。逐页浏览器验收可在 `fixture.json` 设置 `pagination: true`（首页至少四页、搜索 WordPress 至少两页）及 `bookmarks`（友链数量，含长名称和长说明），以覆盖窄屏分页与友链可读性。

`native-counter-concurrency.py` 仅在可销毁 fixture 中运行，覆盖冷初始化、已有计数和同 IP 竞争；验证唯一元数据行、桶写入次数及五分钟 TTL。`native-counter-cache-regression.php` 使用真实 Core API 与显式的两请求缓存边界替身，不等于安装 Redis/Memcached 的集成验收。

`native-reading-count-regression.php` 在引导真实 WordPress 前设置 `DOING_AJAX` / `WP_ADMIN`，按登录状态分发 `zen_post_view` 对应的原生 AJAX hook；不替换主题函数、JSON 输出、元数据或 transient API。覆盖匿名累计、登录/爬虫只读、选项关闭、文章类型/发布状态/密码保护拒绝，以及服务端 IP 五分钟去重、IPv6 等价写法和转发头不可信边界。仅用异常接管原生 `wp_die_ajax_handler` 以捕获 JSON 并在 `finally` 清理自建文章/去重桶、恢复选项；意外提前终止会返回非零。须看到 `NATIVE_READING_COUNT_COMPLETE` 且退出码为 0。这是原生 hook/JSON/数据库验收，不启动 HTTP 服务，也不证明 Web 服务器、整页缓存或浏览器请求链路。

## 浏览器

`python tests/editorial-regression.py /path/to/fixture` 检查标准列表、首屏最新文章卡片、分页去重及四种宽度的日期栏与摘要对齐。可追加 `--settings`，用隔离管理员 Cookie 保存并复原最新卡片开关，检查首页、搜索、文章和归档的日期格式。需要有站点简介、年度归档和至少两页文章的隔离样站。

```sh
python tests/browser-regression.py http://127.0.0.1:18765 /path/to/fixture.json
```

需要 Python、Playwright 和 `/usr/bin/chromium`。只接受本机隔离站点；以单实例串行执行，并由外部 cgroup 限制整个测试进程树内存。不要指向生产数据库。

隔离站点启用当前主题、保留目录/灯箱/返回顶部默认开关；准备以下公开内容，将原生文章/页面 ID 写入 JSON：

- `normal`：含多个 h2/h3、图片及足够滚动的长正文。
- `split`：无 h2/h3 的短文章。
- `longtitle`：含长连续英文标题的文章。
- `archives`：使用 Archives Template 的页面。
- `links`：使用 Links Template，介绍中含长网址的页面。

脚本仅浏览页面，不提交后台设置。整体版面最大宽度固定 1000px，正文宽度独立可调。外部资源被屏蔽，不据此判断字体网络性能。

分类轨和页脚导航专项（固定 loopback 端口 18765）：

```sh
python tests/category-navigation-regression.py /path/to/evidence CATEGORY_ID
python tests/category-navigation-regression.py /path/to/rtl-evidence CATEGORY_ID --rtl
python tests/category-navigation-regression.py /path/to/rtl-compat-evidence CATEGORY_ID --rtl --without-dir-selector
python tests/footer-navigation-regression.py Home About RSS --custom
```

分类轨需要六个以上已发布文章所属的分类，以覆盖横滚、键盘、长焦点链接和无脚本；`--rtl` 设置真实页面方向，`--without-dir-selector` 模拟浏览器不支持 `:dir()`。页脚传实际菜单链接文字，RSS 应处末项；分别测试默认／已指派菜单。自定义页脚为空时省略 `--custom`，设为 `<a href="/extra/">Extra</a>` 时加上它。覆盖断点两侧的视觉和 Tab 顺序、关闭脚本，以及模拟不支持 `reading-flow` 时的回退。全部场景串行，在同一 4 GiB、swap=0 cgroup 内运行。

基础体验测试使用同一隔离站点：

```sh
python tests/experience-regression.py http://127.0.0.1:18765 /path/to/fixture.json
```

额外准备 `page` 普通页面、`direct` 原生代码文章、`synced` 引用已发布代码样板的文章及至少一个页脚菜单链接。`normal` 包含四张原生媒体库图片：首图在首屏，第四张文件名以 `distant` 开头，放在足够长的正文之后；接着放带尺寸的 `/embed.html` iframe、代码、末节及三秒 `/tone.wav` 原生音频。不要预填图片的 `loading` 属性，由 `the_content` 生成。保持固定 1000px 版面、auto 外观及相关开关，使用本地主题字体栈。覆盖媒体请求时机、滚动/减弱动效、键盘焦点、暗色模式、音频、复制、响应式页面及禁用 JS 的内容回退。

原生内容块定向验收：

```sh
python tests/native-content-regression.py /path/to/fixture
python tests/native-layout-regression.py /path/to/fixture
python tests/native-editor-regression.py /path/to/fixture
python tests/typography-regression.py /path/to/fixture
python tests/native-media-typography-regression.py /path/to/fixture
python tests/ui-detail-regression.py /path/to/fixture
```

复用下面原生设置测试的隔离目录结构及 `media` 附件 ID，并准备本地 `tone.wav`、`embed.html`。测试会创建并删除自己的文章，覆盖正文列表、按钮、7 种嵌入比例、Core/Zen 媒体所有权及折叠目录；可追加 `css`、`media` 或 `details` 仅补测对应部分。浏览器仍须串行并由外部 cgroup 限制内存。

新增布局检查覆盖六级标题、显式字号、原生表格内外间距、宽幅／全宽嵌入、评论同意行、16px输入、44px分页和延迟主脚本下的目录首屏稳定性。编辑器检查通过原生 block editor iframe 比较六级标题与前台的字号和间距，并核实标题、正文及代码的实际仓耳字体。字体专项 `typography-regression.py` 需要 `fixture.json` 的 `origin`、`routes`（首页、分类、标签、作者、日期、搜索，每类至少两页）及可选 `surfaces`（普通页、文章、目录、书签、留言、密码页、空搜索和404），安装包含字库的完整 ZIP，并为 `links` 准备至少一个带真实图片的书签。它逐个文本节点与表单控件比较字体，核对分页与「全部篇目」的常态／悬停样式、真实字形来源及书签圆形黑白／悬停彩色。仅在源码、包、配置及数据库未变时可加 `--resume` 续跑 JSONL 未完成项。这些测试都需要带 `.zen-isolated-fixture` 标记的 loopback 环境，并验证浏览器与测试进程同属合计不超过4GiB、swap=0的共同父cgroup。

`ui-detail-regression.py` 定向检查首屏配色、正文强调、留言间距与同意说明、分类／标签日期栏、年度折叠及新增年份焦点、原生兼容复制、MediaSource 真实时长变化和后台窄屏提示。可追加 `first-paint`、`emphasis`、`guestbook`、`taxonomy`、`archive-years`、`clipboard`、`audio-duration` 或 `admin`；禁脚本归档需单独运行 `archive-noscript`（新浏览器，避免单进程多上下文限制）。需要 `surfaces.article`（含 strong/em、代码和一个原生 audio）、`surfaces.notes`、默认 800px／standard 设置及管理员 Cookie；不伪造浏览器媒体属性或复制结果。

以上测试文件不进入主题发布 ZIP。

`native-media-typography-regression.py` 另需 `runtime/media-font.wav` 与 `runtime/media-font.webm` 两个可实际播放的本地音视频文件。它创建并清理原生 `[audio]`／`[video]` 文章，核对 Core MediaElement 文本及控件字体、实际字形来源与播放／暂停，同时确认普通音频仍由 Zen 增强且没有重复播放器。

## 内容颜色与交互边界

```sh
. /opt/test-tools/env.sh
python tests/browser-checkers-regression.py
python tests/design-boundaries-regression.py /path/to/fixture
python tests/native-interaction-regression.py /path/to/fixture
```

颜色测试覆盖 Cover/Group 的局部黑白文字、浅深模式、显式标题色和无脚本原生搜索；可追加 `colors` 或 `noscript`。交互测试通过延迟真实高亮库与 WAV 响应，覆盖音频取消/失败单播放器、目录 Enter 后的 Tab 顺序、Escape 焦点返回，以及高亮未到达时基础交互可用；可追加 `audio`、`toc` 或 `highlight`。这些测试要求上述 loopback fixture，浏览器串行且使用外部内存硬限，不伪造媒体状态。

列表宽度测试在固定 1000px 版面下跑15个原生保存/视口场景，检查两种模式均保留最新卡片摘要；仍断言真实文章ID与非空样本。`native-settings-regression.py --layout-only` 只验证原生设置保存及首页、分类、标签、搜索和正文的受影响展示；由调用者快照并恢复隔离数据库。逐页测试可用 `--routes=tag,article` 只补指定页面族，报告仅对应几何场景，不包含完整交互与后台验收。

## 定向修复验收

```sh
python tests/repair-ui-regression.py /path/to/fixture
```

复用上述隔离 fixture，另需 `long` 指向使用超长分类名的文章，`normal` 末尾包含 `/tone.wav` 原生音频，文件放在 `runtime/tone.wav`。覆盖登录管理栏、长分类元信息、无脚本中间断点、搜索空白关闭、目录双向高亮、数字必填校验和音频透明命中区。可在末尾传 `adminbar`、`metadata`、`noscript`、`search`、`toc`、`toc-anchor`、`required` 或 `audio` 单独补测。音频测试以原始本地 WAV 响应字节范围请求，补足 PHP 开发服务器不支持 Range 的限制；不伪造媒体状态。

浏览器单实例串行，仍须外部已验证 cgroup 内存硬限制；已有有效结果不因交付重复运行。

## 原生设置与设计回归

```sh
python tests/design-browser-regression.py /path/to/fixture green
python tests/native-settings-regression.py /path/to/fixture
python tests/native-advanced-code-regression.py /path/to/fixture
python tests/native-boundary-regression.py /path/to/fixture
python tests/list-width-regression.py /path/to/fixture green
python tests/page-review-regression.py /path/to/fixture
. /opt/test-tools/env.sh
python tests/archive-spacing-regression.py
```

只用于可销毁的隔离 fixture，目录包含 `runtime/`、`db/zen.sqlite`、`fixture.json`、`evidence/`、管理员原生 Cookie 文件 `cookies.json`；权限边界测试另需 `restricted-cookies.json`，其用户具有 `manage_options` 但不具有 `unfiltered_html`。这些脚本会修改 fixture 设置、主题 mods 和样本文章，不得复用生产配置。Cookie 文件须 0600，测试后连同数据库清理。

列表宽度测试在固定 1000px 版面下执行15个原生保存/视口场景，`--focused` 保留为兼容参数；仍断言真实文章ID与非空样本。逐页测试的 `fixture.json` 还需 `tag`、`emptycat`、`protected`、`multipage` 对象ID，覆盖15类前台页面、后台及其实际操作。站点固定为 `http://127.0.0.1:18765`。设计测试复现目录遮字、字段内部换行及编辑器排版差异；设置测试覆盖旧值保留、空值清除、校验错误、代码输出与320–1440px固定保存栏；边界测试覆盖Logo、特色图、字体、宽幅媒体和伪造代码提交。浏览器仍须单实例串行并置于外部已验证内存限制内。
