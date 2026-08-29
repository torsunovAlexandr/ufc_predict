import { createRouter, createWebHistory } from 'vue-router'

const routes = [
  {
    path: '/',
    name: 'dashboard',
    component: () => import('@/views/DashboardView.vue'),
    meta: { title: 'Дашборд' },
  },
  {
    path: '/events',
    name: 'events',
    component: () => import('@/views/EventsView.vue'),
    meta: { title: 'Турниры' },
  },
  {
    path: '/events/:id',
    name: 'event',
    component: () => import('@/views/EventView.vue'),
    props: true,
    meta: { title: 'Турнир' },
  },
  {
    path: '/fights/:id',
    name: 'fight',
    component: () => import('@/views/FightView.vue'),
    props: true,
    meta: { title: 'Прогноз на бой' },
  },
  {
    path: '/statistics',
    name: 'statistics',
    component: () => import('@/views/StatisticsView.vue'),
    meta: { title: 'Статистика' },
  },
  {
    path: '/history',
    name: 'history',
    component: () => import('@/views/HistoryView.vue'),
    meta: { title: 'История боёв' },
  },
  {
    path: '/fighters',
    name: 'fighters',
    component: () => import('@/views/FightersView.vue'),
    meta: { title: 'Бойцы' },
  },
  {
    path: '/settings',
    name: 'settings',
    component: () => import('@/views/SettingsView.vue'),
    meta: { title: 'Настройки' },
  },
  {
    path: '/:pathMatch(.*)*',
    name: 'not-found',
    component: () => import('@/views/NotFoundView.vue'),
    meta: { title: 'Страница не найдена' },
  },
]

const router = createRouter({
  history: createWebHistory(),
  routes,
  scrollBehavior: () => ({ top: 0 }),
})

router.afterEach((to) => {
  document.title = to.meta.title ? `${to.meta.title} — UFC Predict` : 'UFC Predict'
})

export default router
