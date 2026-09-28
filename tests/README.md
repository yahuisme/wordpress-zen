# 回归测试

## PHP 行为

```sh
php tests/php-regression.php
php tests/assets-regression.php
php tests/custom-code-regression.php
php tests/seo-regression.php
```

需要 PHP 8.0+。使用明确的 WordPress 边界替身，覆盖分页、缓存钩子、搜索高亮、评论、友链目标及模板输出；不替代真实 WordPress 验收。

代码高亮回归需要 Node.js 与 `jsdom`，实际执行主题脚本及本地 Highlight.js：

```sh
node --test tests/highlight-regression.cjs
```

`jsdom` 安装在独立工具目录时，通过 `NODE_PATH` 指向其 `node_modules`。

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

以上测试文件不进入主题发布 ZIP。
