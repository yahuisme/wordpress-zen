# Zen

专注排版、留白与阅读的 WordPress 博客主题，使用原生文章、页面、评论和友链数据，无需配套插件或构建步骤。

当前系列重做了首页、阅读页和归档。想保留旧版界面，可从 [上一系列的最终版](https://github.com/yahuisme/wordpress-zen/releases/tag/v1.6.5) 下载 `zen.zip`。

![Zen 首页截图](theme/zen/screenshot.jpg)

- 仓耳今楷统一排版，适配手机与桌面，支持浅色、深色和跟随系统外观。
- 首页可选最新文章卡片。普通列表有标准和紧凑两种样式，紧凑样式隐藏列表摘要，不影响卡片摘要。另有归档、标签、友链和留言模板。
- 文章支持标题目录、代码高亮与复制、图片灯箱、阅读进度和预计阅读时间。

## 安装与使用

**环境要求：WordPress 6.5+、PHP 8.0+。**

1. 从 [Releases](https://github.com/yahuisme/wordpress-zen/releases) 下载对应版本的附件 **`zen.zip`**，不要选 GitHub 自动生成的 `Source code` 压缩包。
2. 在后台「外观 → 主题 → 安装主题 → 上传主题」安装并启用 Zen。
3. 在「设置 → 常规」填写站点标题、副标题；Logo 在「外观 → 自定义 → 站点身份」设置。
4. 打开一级菜单「Zen 主题设置」，调整排版、首页列表、文章详情、页脚等选项并保存。

Zen 不内置更新检查，请到 Releases 下载新版。更新前备份数据库和主题文件，另存直接改过的主题文件。上传新 `zen.zip` 后选择替换当前主题；同版本也可替换，无需重新导入内容。若启用了页面缓存、CSS／JS 优化缓存或 CDN，更新后清理对应缓存。

主题不会自动创建页面或导入演示内容。正文宽度默认 800px，可在 600–960px 内调整；文章目录取自 H2／H3 标题。高级代码仅供有 `unfiltered_html` 权限的账号填写可信代码。

## 页面与导航

在「页面 → 新建页面」选择对应模板并发布，标题和链接别名可自行设置：

| 用途 | 模板 | 建议别名 |
| --- | --- | --- |
| 归档 | `Archives Template` | `archives` |
| 标签 | `Tags Template` | `tags` |
| 友链 | `Links Template` | `links` |
| 留言 | `Notes Template` | `notes` |

- **归档与标签：** 自动汇总已发布文章及已使用标签。归档每批显示两个有文章的年份，默认展开；年份右侧可统一收起／展开该年月份，无脚本也可浏览完整目录。
- **友链：** 在后台「链接 → 添加」维护名称、网址、描述和图片；排序数字越小越靠前，`0` 排在最后。Logo 默认圆形黑白，悬停恢复彩色。
- **留言：** 编辑页面时在「讨论」中开启「允许评论」；审核和登录要求由「设置 → 讨论」控制。
- **页脚导航：** 默认显示已发布的模板页面；也可在「外观 → 菜单」创建菜单并指派到 `Footer Menu`。

## 字体与许可

当前系列的 `zen.zip` 内置仓耳今楷，源码仓库不含该字库。**字体版权与使用、再分发授权独立于主题 GPL**，请遵循包内字体说明。

主题代码采用 [GPL-3.0](LICENSE)，第三方资源许可见 [licenses](theme/zen/licenses/)。原作者 [qwer-xyz](https://github.com/qwer-xyz)，维护者 [RyanZ](https://github.com/yahuisme)。

## 开发

源码位于 [`theme/zen/`](theme/zen/)，另见[测试说明](tests/README.md)和[更新记录](CHANGELOG.md)。[手动打包工作流](https://github.com/yahuisme/wordpress-zen/actions/workflows/release.yml)只上传 `zen.zip` 和 `release-notes.md` 供人工发布。推送不会触发该工作流；手动运行也不会创建标签或发布 Release。
