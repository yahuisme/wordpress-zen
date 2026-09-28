# WordPress Zen Theme

一个极简的 WordPress 主题，专注于排版、留白与沉浸式阅读。

- 原作者：[qwer-xyz](https://github.com/qwer-xyz)
- 维护者：[RyanZ](https://ryanz.de/)
- 项目主页：https://github.com/yahuisme/wordpress-zen
- 主题演示：https://ryanz.de

## 安装

在 WordPress 后台进入“外观” → “主题” → “安装主题” → “上传主题”，选择主题 ZIP 并安装启用。

也可以将 `zen` 主题目录上传至 `wp-content/themes/`。

## 主题设置

主题设置位于后台左侧的「Zen 主题设置」一级菜单中，位置在 WordPress 自带的「设置」菜单下方。

- 「界面 → 字体显示」可选择系统字体，停止加载外部字体。
- 「高级 → 自定义代码」可填写头部、正文起始和页脚 HTML 或统计脚本，留空不输出；仅向具备 `unfiltered_html` 权限的管理员开放。
- 阅读量统计可独立关闭；整页缓存命中时不会计数。
- 启用 Yoast、Rank Math、AIOSEO 或 SEOPress 时，主题让出描述和文章结构化数据输出。

### 归档页面

新建页面，将模板设置为 `Archives Template`。

### 友链页面

新建页面，将模板设置为 `Links Template`，然后在 WordPress 的“链接”中添加链接。

## 许可证

本项目采用 [GPL-3.0](LICENSE) 许可证。
