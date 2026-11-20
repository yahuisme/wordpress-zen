# Zen

专注排版与阅读的 WordPress 博客主题，支持浅色、深色外观

## 安装与使用

- 要求 WordPress 6.5+、PHP 8.0+
- 从 [Releases](https://github.com/yahuisme/wordpress-zen/releases) 下载 `zen.zip`，在「外观 → 主题」上传并启用
- 后台「Zen 主题设置」调整阅读与展示选项；整体版面固定 1000px，正文默认 800px
- 页面模板提供归档、友链、标签和留言页，留言页需允许评论
- 归档每次显示 2 个有文章的年份，月份默认全部展开；文章列表统一使用前后翻页
- 高级代码仅供有 `unfiltered_html` 权限的用户填写可信 HTML 或脚本
- 更新前备份；更新后清理页面、脚本与 CDN 缓存

Release 的 `zen.zip` 已含仓耳今楷网页字体，安装即可使用；字体版权与授权独立于主题 GPL，详见包内字体说明。源码仓库不保存仓耳字库。

## 开发与许可

源码在 `theme/zen/`，回归说明见 [tests/README.md](tests/README.md)，无需构建

原作者 [qwer-xyz](https://github.com/qwer-xyz)，维护者 [RyanZ](https://ryanz.de/)，设计参考 [bins.blog](https://bins.blog/) 与 [Kami](https://kami.tw93.fun/index-zh.html)

[GPL-3.0](LICENSE)；第三方许可见 [licenses](theme/zen/licenses/) 与 [字体许可](theme/zen/assets/fonts/google-sans-code/OFL.txt)
