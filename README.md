# wordpress-zen

Zen WordPress 主题项目，主题源码与回归测试分开维护。

## 目录结构

- `theme/zen/`：可直接安装的 Zen 主题源码与发布说明。
- `tests/`：PHP、JavaScript、原生 WordPress 与浏览器回归测试。
- `.github/workflows/release.yml`：手动打包主题 ZIP，不自动发布 Release。
- `AGENTS.md`：项目开发约束。

## 测试

测试命令与隔离 WordPress 验收说明见 [`tests/README.md`](tests/README.md)。主题安装、设置和使用说明见 [`theme/zen/README.md`](theme/zen/README.md)。
