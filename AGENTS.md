# AGENTS instructions

本文件为在 WordPress Zen Theme 仓库中工作的代码代理提供约束。这个仓库是 WordPress 主题，不是独立应用，也不是插件。所有改动都应服务于主题的核心目标：安静、克制、以阅读为中心的个人博客和技术文章 UI。

## Naming

- 主题名使用 `WordPress Zen Theme` 或 `zen`。不要随意引入新的品牌名。
- PHP 函数使用 `zen_` 前缀。
- CSS 自定义组件类使用 `zen-` 前缀。
- WordPress 模板文件名保持 WordPress 约定，例如 `single.php`、`archive.php`、`page-archives.php`。
- 页面模板名称保持稳定，例如 `Archives Template`、`Links Template`，因为用户可能已经在后台选择了这些模板。
- 文案默认使用简洁中文。代码 token、路径、命令、类名和函数名保持原样。

## Environment Setup

- 本主题运行在 WordPress 中。不要为主题引入独立服务端框架。
- Node.js 只用于 Tailwind 构建。
- 生产主题包不应依赖 `node_modules/`、`src/`、`package.json` 或 Tailwind 配置。
- 如果需要本地预览，优先使用已有 WordPress 开发环境，而不是另起一个非 WordPress 应用。

安装依赖：

```bash
npm ci
```

## Commands

开发监听 Tailwind：

```bash
npm run dev
```

构建生产 CSS：

```bash
npm run build
```

检查 PHP 语法，如果本机有 PHP：

```bash
php -l path/to/file.php
```

修改 `src/input.css` 或模板中的 Tailwind class 后，运行 `npm run build`，并确认 `assets/css/style.css` 已更新。

## Repository Structure

- `functions.php`：主题入口，只负责加载 `inc/` 模块。
- `inc/setup.php`：主题能力、菜单、链接管理器、资源提示和全局过滤器。
- `inc/enqueue.php`：前端 CSS、JavaScript、字体、图标和代码高亮资源。
- `inc/template-tags.php`：模板复用函数，例如分类、日期、分页、搜索高亮和归档缓存。
- `inc/schema.php`：文章页 JSON-LD 结构化数据。
- `inc/comments.php`：评论列表自定义渲染。
- `header.php`：全局头部、导航、主题初始化、SEO 描述和搜索弹窗。
- `footer.php`：页脚、图片灯箱和返回顶部按钮。
- `index.php`、`archive.php`、`search.php`、`single.php`、`page.php`、`404.php`：WordPress 模板。
- `page-archives.php`：文章归档页面模板。
- `page-links.php`：友情链接页面模板，读取 WordPress 链接管理器数据。
- `template-parts/content-excerpt.php`：文章列表摘要组件。
- `js/main.js`：前端交互。
- `src/input.css`：Tailwind 源样式。
- `assets/css/style.css`：编译后的样式。
- `assets/js/`：第三方浏览器资源，不要随意重写压缩库。

## Architecture Boundaries

这个仓库必须保持主题边界：

1. WordPress 负责内容、路由、后台和数据模型。
2. PHP 模板负责输出 WordPress 页面结构。
3. `inc/` 中的辅助函数负责复用逻辑，不承担大型业务模块。
4. `js/main.js` 负责轻量前端交互，不承担应用状态管理框架职责。
5. `src/input.css` 负责视觉系统和组件样式，`assets/css/style.css` 是构建产物。
6. 不添加自定义数据库表。
7. 不把 SEO 插件、统计插件、表单系统、会员系统等插件职责塞进主题。
8. 不能让生产运行依赖构建工具。

如果需求明显属于插件能力，说明边界并给出插件化建议，不要直接内嵌到主题。

## UI Design Standards

UI 是本主题最重要的维护面。所有视觉改动必须符合以下方向：

- 内容优先，阅读优先。
- 优先打磨字体、行高、间距、对比度、留白和滚动体验。
- 保持窄版正文宽度，尊重现有 `max-w-zen`。
- 避免营销落地页式布局、夸张 hero、大面积装饰、过度卡片化和视觉噪音。
- 不使用单一色相堆叠出的廉价主题感。
- 不滥用渐变、发光、装饰圆点、背景图案或复杂阴影。
- 卡片和浮层要克制，不能出现卡片套卡片。
- 图标按钮应优先使用已有 Phosphor Icons，并提供可访问名称。
- 页面元信息、导航、标签和操作按钮必须低调，不能抢正文注意力。
- 动效要短、轻、稳定，不制造布局跳动。
- 所有文本都必须在移动端和桌面端完整可读，不得重叠、裁切或溢出。

文章详情页的 UI 重点是沉浸阅读。文章列表页的 UI 重点是快速扫描。搜索、评论、归档和友链页面应保持安静、清晰、实用。

## Responsive Layout

所有 UI 改动至少考虑移动端和桌面端：

- 顶部导航和工具按钮在窄屏下不能挤压站点标题。
- 文章列表在手机上必须保持可扫描。
- 正文行长不能过宽。
- 大屏目录保持侧边栏体验，小屏目录保持抽屉或浮动按钮体验。
- 搜索弹窗、评论表单、友链卡片、归档列表、媒体块不能造成横向滚动。
- 固定定位控件不能遮挡正文、评论输入框或系统手势区域。
- 按钮、工具栏、媒体容器和重复列表项应有稳定尺寸，避免 hover/focus 改变布局。

## Accessibility Model

可访问性是 UI 质量的一部分：

- 保留语义化结构、landmark、跳转链接和屏幕阅读器文本。
- 弹窗类 UI，包括搜索、图片灯箱、目录抽屉，必须支持 Escape 关闭、焦点进入、焦点返回。
- 交互控件必须可键盘操作。
- `aria-expanded`、`aria-controls`、`aria-modal`、`aria-current` 等状态要与实际 UI 同步。
- 不删除可见 focus 样式。
- 浅色和深色模式都要保持足够对比度。
- 只用图标表达操作时，必须提供 `aria-label`、`title` 或 `.screen-reader-text`。

## Dark Mode

深色模式由 `<html>` 上的 `dark` class 和 `zen-theme-mode` localStorage key 控制。

- 修改颜色时优先调整 `src/input.css` 中的 `--zen-*` 变量。
- 避免只在浅色或深色模式可读的硬编码颜色。
- 保持 `header.php` 中的首屏主题初始化脚本和 `js/main.js` 中的切换逻辑一致。
- 修改任何背景、边框、文本、阴影后，都要检查 light、dark、auto 三种模式。

## JavaScript Standards

`js/main.js` 当前负责：

- 主题模式切换。
- Highlight.js 初始化。
- 图片灯箱。
- 文章目录生成和滚动高亮。
- 阅读进度和返回顶部。
- 移动端菜单。
- 自定义音频播放器。
- 搜索弹窗和快捷键。

编辑 JavaScript 时：

- 优先使用原生浏览器 API。
- 页面应在 JavaScript 失败时仍能呈现基本内容。
- DOM 查询后先判空，再绑定事件。
- 滚动监听使用 passive listener；滚动 UI 更新优先放进 `requestAnimationFrame`。
- 维护所有交互控件的 ARIA 状态。
- 不引入前端框架。
- 不把一次性页面逻辑扩展成复杂状态系统。

## PHP and WordPress Standards

- 所有输出按上下文使用 `esc_html()`、`esc_attr()`、`esc_url()` 或 `wp_kses()`。
- 不直接输出不可信 HTML，除非有明确 allowlist。
- URL、资源、菜单、评论、查询和模板加载使用 WordPress API。
- 自定义查询后调用 `wp_reset_postdata()`。
- 避免直接 SQL。
- 辅助函数放在 `inc/template-tags.php` 或更合适的 `inc/` 文件中。
- 模板保持可读；重复或难读的渲染逻辑抽成 `zen_` helper。
- 不破坏 WordPress 模板层级和用户后台设置。

## CSS and Tailwind Standards

- `src/input.css` 是样式源文件。
- `assets/css/style.css` 是构建产物。
- 不手动编辑构建产物，除非任务明确要求。
- 共享视觉模式放进 `@layer components`。
- 局部布局可以用 Tailwind class，但不要让模板堆满难以维护的长 class 串。
- 优先复用现有 `--zen-*` 变量、`.zen-*` 组件和 Tailwind typography/forms 配置。
- 新样式应同时考虑正文、评论、搜索、目录、友链、归档、媒体块和代码块的整体一致性。

## Content Templates

文章列表：

- 保留分类、日期、标题、摘要和“阅读更多”结构。
- 搜索结果标题高亮必须保持安全转义。
- 不把列表改成厚重卡片流，除非这是明确的整体视觉重设计。

文章详情：

- 保留由 `h2`、`h3` 生成目录的行为。
- 保留标签区域和评论区域。
- 保持代码块、图片、文件、嵌入和音频的阅读体验。

自定义页面：

- `page-archives.php` 保持缓存友好。
- `page-links.php` 使用 WordPress bookmarks/link manager 数据。
- 不随意更改模板文件名或 Template Name。

## SEO and Metadata

主题只提供基础 SEO，不承担完整 SEO 插件职责。

必须保留：

- `title-tag` 支持。
- 搜索结果页 `noindex, follow`。
- meta description 生成。
- 文章页 JSON-LD。
- RSS feed 链接。

不要默认加入统计、追踪脚本或侵入性 metadata。

## Assets and Dependencies

当前依赖应保持克制：

- Tailwind CSS。
- `@tailwindcss/typography`。
- `@tailwindcss/forms`。
- Phosphor Icons。
- Highlight.js。

新增依赖前，先确认是否可以用 WordPress API、浏览器 API 或现有 Tailwind/CSS 完成。新增依赖必须带来明确的 UI 或维护收益。

## Testing and Verification

UI 改动至少检查：

- 首页文章列表。
- 单篇文章，包括标题、目录、代码块、图片、标签和评论。
- 搜索弹窗。
- 移动端菜单。
- 文章归档模板。
- 友情链接模板。
- 404 页面。
- light、dark、auto 三种主题模式。
- 手机宽度和桌面宽度。

PHP 改动至少检查相关模板是否能正常渲染。CSS 或 Tailwind class 改动必须运行：

```bash
npm run build
```

## Release Packaging

GitHub Actions 会在推送到 `main` 后构建 CSS 并打包 `zen.zip`。

发布包排除：

- `src/`
- `node_modules/`
- `.git/`
- `.github/`
- `package.json`
- `package-lock.json`
- `tailwind.config.js`
- `README.md`

运行时不能依赖这些被排除的文件。

## Pull Request Expectations

UI 相关 PR 应说明：

- 视觉上改了什么。
- 检查了哪些页面、状态和屏幕宽度。
- 是否运行了 `npm run build`。
- 是否影响 light/dark/auto。

PHP 相关 PR 应说明：

- 改了哪些模板、hook 或 helper。
- 是否有缓存影响。
- 是否有 WordPress 兼容性假设。

保持 PR 聚焦。不要把大范围视觉重设计和无关 PHP 行为改动混在一起。
