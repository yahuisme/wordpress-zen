# Zen

一个以排版和阅读为主的 WordPress 博客主题，采用暖纸底、墨蓝与深炭两套外观。

首页用一张最新文章卡片开启阅读，其余文章按日期列出。设计参考 [bins.blog](https://bins.blog/) 与 [Kami 紙](https://kami.tw93.fun/index-zh.html)。

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

- 前台与编辑器使用相同字体栈。版面默认 992px、正文默认 800px、摘要默认 60 字；升级保留已保存的设置。
- 「首页与文章列表」可关闭最新文章卡片、选择列表密度。紧凑模式省略摘要。所有分组一次保存。
- 站点身份、Logo、站点图标、页脚菜单、阅读与讨论在 WordPress 原生页面维护。默认页脚菜单包含已发布的 `about` 页面；启用 RSS 时，订阅链接排在页脚导航最后。
- 文章编辑器与前台共享正文排版；特色图在文章页展示，首页不加载文章缩略图。
- 「高级代码」默认折叠，可填写头部、正文起始和页脚 HTML 或脚本；仅向具备 `unfiltered_html` 权限的管理员开放，更换主题后不再输出。
- 浏览次数默认关闭；启用后为简单累计计数，同一服务端识别的 IP 对同一文章五分钟内只计一次。文章页加载后通过同源 AJAX 更新，因此整页缓存命中仍可计数；关闭后停止计数。已有明确设置值保留。主题不信任请求中的 X-Forwarded-For；使用反向代理时，请在 Web 服务器层仅对可信代理解析真实 IP，否则同一代理后的访客会共享去重桶。
- 新安装不预设文章授权协议；已有明确选择保留。页脚不再内置主题和 WordPress 署名，保留自定义页脚及 RSS 开关。退役选项不再生效，也不会被升级或重置删除。
- 启用 Yoast、Rank Math、AIOSEO 或 SEOPress 时，主题让出描述和文章结构化数据输出。

从 1.3.4 或更早版本升级时，请核对「版权协议」。如果以前选择了协议，但关闭了旧版的版权显示开关，请将协议设为「不显示」。

### 原站字体

正文、标题优先使用仓耳今楷 02 W04，英文代码及等宽信息用 Google Sans Code，中文代码优先使用仓耳的 CJK 分片。Google Sans Code 随主题自托管；不请求参考站或第三方字体。公开包不含仓耳网页字库，未安装授权网页字体时使用访客本机字体或系统回退字体。

若已取得[仓耳今楷 02 的网页使用授权](https://tsanger.cn/product/33)，将自有的 `@font-face` 样式放入 `wp-content/themes/zen/assets/zen-kai/assets/css/zen-bins-kai.css`，声明 `Zen Kami Kai`（正文和标题）和 `TsangerJinKai02 Code CJK`（仅中文代码字形），字体使用相对 URL。主题会在前台和区块编辑器同时加载；请单独备份，更新主题后恢复，不要提交或公开分发该目录。参考站分片不覆盖所有汉字，缺字仍会像原站一样回退。

### 归档页面

新建页面，将模板设置为 `Archives Template`。

### 友链页面

新建页面，将模板设置为 `Links Template`，然后在 WordPress 的“链接”中添加链接。

标签索引和留言簿分别使用 `Tags Template`、`Notes Template`。留言簿在历史留言前显示「写留言」表单，提交和审核由 WordPress 原生评论管理。需在留言页面的「讨论」面板开启「允许评论」。

## 许可证

本项目采用 [GPL-3.0](LICENSE) 许可证。第三方资源许可见 `licenses/`，以及 [Google Sans Code](assets/fonts/google-sans-code/OFL.txt)（SIL OFL 1.1）。
