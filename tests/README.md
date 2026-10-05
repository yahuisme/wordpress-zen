# 回归测试

## 发布包

```sh
python3 tests/package-regression.py
```

使用独立 Git 索引打包当前工作区，不修改暂存区或创建提交；执行发布工作流的 ZIP 校验，覆盖显示名、版本、环境要求、文件清单及错误元数据拒绝。需要 Python 3.9+ 与 Git。

## PHP 行为

```sh
php tests/php-regression.php
php tests/assets-regression.php
php tests/custom-code-regression.php
php tests/seo-regression.php
php tests/template-design-regression.php
php tests/design-assets-regression.php
php tests/admin-options.php
```

需要 PHP 8.0+。使用明确的 WordPress 边界替身，覆盖分页、缓存钩子、搜索高亮、评论、友链目标及模板输出；不替代真实 WordPress 验收。

代码高亮回归需要 Node.js 与 `jsdom`，实际执行主题脚本及本地 Highlight.js：

```sh
node --test tests/highlight-regression.cjs tests/interaction-regression.cjs tests/admin-ui.cjs
```

`jsdom` 安装在独立工具目录时，通过 `NODE_PATH` 指向其 `node_modules`。

## 原生 WordPress

```sh
ZEN_TEST_ISOLATED=1 php tests/native-regression.php /path/to/isolated/wp-load.php
```

仅用于 `WP_ENVIRONMENT_TYPE=local`、站点地址为 `127.0.0.1` 的一次性安装，启用当前 Zen 主题。测试会创建并删除自己的样板/文章、临时启用并恢复计数开关，覆盖同步样板、循环引用、可见性与爬虫过滤；必须看到 `NATIVE_COMPLETE` 且退出码为 0。

## 浏览器

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

脚本仅浏览页面，不提交后台设置。建议分别将内容宽度设为600、900、1200和1920复测。外部资源被屏蔽，不据此判断字体网络性能。

基础体验测试使用同一隔离站点：

```sh
python tests/experience-regression.py http://127.0.0.1:18765 /path/to/fixture.json
```

额外准备 `page` 普通页面、`direct` 原生代码文章、`synced` 引用已发布代码样板的文章及至少一个主菜单链接。`normal` 包含四张原生媒体库图片：首图在首屏，第四张文件名以 `distant` 开头，放在足够长的正文之后；接着放带尺寸的 `/embed.html` iframe、代码、末节及三秒 `/tone.wav` 原生音频。不要预填图片的 `loading` 属性，由 `the_content` 生成。保持默认 900px 内容宽度、auto 外观及相关开关，选择系统字体以隔离外部网络。覆盖媒体请求时机、滚动/减弱动效、键盘焦点、暗色模式、音频、复制、响应式页面及禁用 JS 的内容回退。

以上测试文件不进入主题发布 ZIP。

## 原生设置与设计回归

```sh
python tests/design-browser-regression.py /path/to/fixture green
python tests/native-settings-regression.py /path/to/fixture
python tests/native-boundary-regression.py /path/to/fixture
python tests/list-width-regression.py /path/to/fixture green
python tests/page-review-regression.py /path/to/fixture
python tests/archive-spacing-regression.py
```

只用于可销毁的隔离 fixture，目录包含 `runtime/`、`db/zen.sqlite`、`fixture.json`、`evidence/`、管理员原生 Cookie 文件 `cookies.json`；权限边界测试另需 `restricted-cookies.json`，其用户具有 `manage_options` 但不具有 `unfiltered_html`。这些脚本会修改 fixture 设置、主题 mods 和样本文章，不得复用生产配置。Cookie 文件须 0600，测试后连同数据库清理。

列表宽度测试通过原生设置保存「标准 → 紧凑 → 标准」，跨3种字体、600/900/1050/1200/1920px版面和320/390/768/1440/2560px视口，比较标题与摘要实际边界。逐页测试的 `fixture.json` 还需 `tag`、`emptycat`、`protected`、`multipage` 对象ID，覆盖15类前台页面、后台及其实际操作。站点固定为 `http://127.0.0.1:18765`。设计测试复现目录遮字、字段内部换行及编辑器排版差异；设置测试覆盖旧值保留、空值清除、校验错误、代码输出与320–1440px固定保存栏；边界测试覆盖Logo、特色图、字体、宽幅媒体和伪造代码提交。浏览器仍须单实例串行并置于外部已验证内存限制内。
