# Zen

一个极简的 WordPress 主题，专注于排版、留白与沉浸式阅读。

- 原作者：[qwer-xyz](https://github.com/qwer-xyz)
- 维护者：[RyanZ](https://ryanz.de/)
- 项目主页：https://github.com/yahuisme/wordpress-zen
- 主题演示：https://ryanz.de

## 安装

环境要求：WordPress 6.5+、PHP 8.0+。

从 [Releases](https://github.com/yahuisme/wordpress-zen/releases/latest) 下载 `zen.zip`，在 WordPress 后台进入“外观” → “主题” → “安装主题” → “上传主题”，安装并启用 Zen。

也可以将 `zen` 主题目录上传至 `wp-content/themes/`。

## 主题设置

主题设置位于后台左侧的「Zen 主题设置」一级菜单中，位置在 WordPress 自带的「设置」菜单下方。

- 「排版与阅读」可选择系统排版（不加载外部字体），调整 600–960px 正文宽度；原有整体版面宽度保留在折叠的高级设置中。
- 设置按文章列表、文章详情、页脚等任务分组；所有分组一次保存，固定保存栏提示未保存修改。
- 站点身份、Logo、站点图标、菜单、阅读与讨论在 WordPress 原生页面维护；主导航支持一级菜单。
- 文章编辑器与前台共享正文排版；特色图在文章页展示，首页维持文字列表。
- 「高级代码」默认折叠，可填写头部、正文起始和页脚 HTML 或脚本；仅向具备 `unfiltered_html` 权限的管理员开放，更换主题后不再输出。
- 浏览次数默认关闭；启用后为简单累计计数，整页缓存命中不计数，关闭后停止计数。已有明确设置值保留。
- 新安装不预设文章授权协议；已有明确选择保留。默认隐藏主题与 WordPress 署名，RSS 保持开启。
- 启用 Yoast、Rank Math、AIOSEO 或 SEOPress 时，主题让出描述和文章结构化数据输出。

### 归档页面

新建页面，将模板设置为 `Archives Template`。

### 友链页面

新建页面，将模板设置为 `Links Template`，然后在 WordPress 的“链接”中添加链接。

## 许可证

本项目采用 [GPL-3.0](LICENSE) 许可证。
