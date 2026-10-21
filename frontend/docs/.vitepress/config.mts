import { defineConfig } from 'vitepress'

const base = process.env.DOCS_BASE || '/'
if (!base.startsWith('/') || !base.endsWith('/') || base.includes('..') || base.includes('://')) {
  throw new Error('DOCS_BASE must be an absolute directory path, such as / or /docs/.')
}

export default defineConfig({
  lang: 'zh-CN',
  title: 'shuv2b',
  description: 'shuv2b 的部署、迁移与使用文档。',
  base,
  cleanUrls: true,
  srcExclude: ['README.md'],
  head: [['link', { rel: 'icon', type: 'image/svg+xml', href: `${base}logo.svg` }]],
  themeConfig: {
    logo: '/logo.svg',
    siteTitle: 'shuv2b 文档',
    socialLinks: [{ icon: 'github', link: 'https://github.com/hajidishu/shuv2b' }],
    nav: [
      { text: '开始使用', link: '/guide/introduction' },
      { text: '部署与迁移', link: '/deploy/linux' },
      { text: '使用指南', link: '/use/subscribe' },
      { text: '分支变更', link: '/CHANGELOG' }
    ],
    sidebar: [
      { text: '开始', items: [
        { text: '项目介绍', link: '/guide/introduction' },
        { text: '术语', link: '/TERM' },
        { text: '环境要求', link: '/ENV' },
        { text: '文档来源与适配', link: '/guide/upstream' }
      ] },
      { text: '部署与维护', items: [
        { text: 'Linux 手动部署', link: '/deploy/linux' },
        { text: 'aaPanel / 宝塔部署', link: '/deploy/aapanel' },
        { text: '从原版v2b迁移', link: '/deploy/migrate' },
        { text: '从 xiaov2b 迁移', link: '/deploy/from-xiaov2b' },
        { text: '队列与定时任务', link: '/deploy/queues' },
        { text: '备份与恢复', link: '/deploy/backup' },
        { text: '更新升级', link: '/use/update' }
      ] },
      { text: '使用', collapsed: false, items: [
        { text: '用户', link: '/use/user' },
        { text: '订阅与套餐', link: '/use/subscribe' },
        { text: '节点', link: '/use/node' },
        { text: '支付', link: '/use/payment' },
        { text: '邮件', link: '/use/email' },
        { text: '邀请返佣', link: '/use/commission' },
        { text: '知识库', link: '/use/knowledge' },
        { text: 'Telegram', link: '/use/telegram' },
        { text: '进阶与前后端分离', link: '/use/advanced' },
        { text: '服务端', link: '/use/server' },
        { text: '主题', link: '/use/theme' }
      ] },
      { text: '本分支功能', items: [
        { text: '管理员前端', link: '/features/admin' },
        { text: '订阅审计', link: '/features/subscription-audit' },
        { text: '人工排查', link: '/features/leak-probe' },
        { text: '支付台账', link: '/features/payment-ledger' },
        { text: '支付回跳配置', link: '/features/payment-return-url' }
      ] },
      { text: '参考', items: [
        { text: '分支变更记录', link: '/CHANGELOG' },
        { text: '常见问题', link: '/reference/faq' },
        { text: '构建与发布文档站', link: '/reference/documentation' }
      ] }
    ],
    search: {
      provider: 'local',
      options: {
        locales: {
          root: {
            translations: {
              button: { buttonText: '搜索文档', buttonAriaLabel: '搜索文档' },
              modal: {
                noResultsText: '未找到相关内容', resetButtonTitle: '清除搜索',
                footer: { selectText: '选择', navigateText: '切换', closeText: '关闭' }
              }
            }
          }
        }
      }
    },
    outline: { level: [2, 3], label: '本页目录' },
    docFooter: { prev: '上一页', next: '下一页' },
    sidebarMenuLabel: '文档目录', returnToTopLabel: '返回顶部',
    darkModeSwitchLabel: '外观', lightModeSwitchTitle: '切换为浅色', darkModeSwitchTitle: '切换为深色',
    footer: {
      message: '根据 V2Board 原文档章节结构及当前分支代码整理 · 非 V2Board 官方文档',
      copyright: 'V2Board 原项目及文档归原作者所有。'
    }
  }
})
