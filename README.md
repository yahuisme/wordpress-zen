# WordPress Zen Theme

一个极简的 WordPress 主题，专注于排版、留白与沉浸式阅读。

- 原作者：[qwer-xyz](https://github.com/qwer-xyz)
- 维护者：[RyanZ](https://ryanz.de/)
- 项目主页：https://github.com/yahuisme/wordpress-zen
- 主题演示：https://ryanz.de
- 许可证：[GNU General Public License v3.0](LICENSE)

## 说明

这是一个由 RyanZ 维护的极简 WordPress 博客主题。本仓库以 GPL-3.0 许可证继续维护和发布。

## 安装

将本仓库中的 `zen` 主题目录（或发行版 ZIP）上传至 WordPress：

1. 在 WordPress 后台进入“外观” → “主题”；
2. 点击“安装主题” → “上传主题”；
3. 选择主题 ZIP，安装并启用。

## 设置

### 主题设置菜单

WordPress 左侧菜单 —— 外观 —— 主题设置。可简单自定义外观、界面、页脚、版权显示等。

### 主题归档页面

添加一个新页面，并将页面模板设置为 `Archives Template` 即可。

### 主题书签 / 友情链接页面

添加一个新页面，并将页面模板设置为 `Links Template`。然后在 WordPress 左侧菜单的“链接”中添加任意链接。如果后台没有“链接”菜单，需要先启用 WordPress 链接管理器兼容功能或安装提供该功能的插件。

## 开源许可

本项目以 GPL-3.0 发布。详见 [LICENSE](LICENSE)。

## 发布说明

推送到 `main` 会运行发布工作流。工作流从 `style.css` 读取版本号；对应的 `vX.Y.Z` Release 不存在时创建新 Release，已存在时仅替换其中的 `zen.zip`。

小幅增量修复不需要修改版本号，工作流会自动替换既有 Release 的 `zen.zip`。明显的新功能或版本发布才增加版本号并创建新的 Release。
