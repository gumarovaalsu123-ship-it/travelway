import React from 'react';
import { Link } from '@inertiajs/react';
import AdminLayout from '../../Components/AdminLayout';
import { plural } from '../../utils/format';

// Высота самого высокого столбика графика в пикселях (как в макете), остальные — пропорционально.
const MAX_BAR_HEIGHT = 140;

function StatCard({ label, stat, accentClass = '' }) {
    return (
        <div className="admin-stat-card">
            <span className="stat-card__label">{label}</span>
            <div className="stat-card__data">
                <span className={`stat-card__number ${accentClass}`}>{stat.total.toLocaleString('ru-RU')}</span>
                <span className="stat-card__growth">+{stat.week.toLocaleString('ru-RU')} за неделю</span>
            </div>
        </div>
    );
}

export default function AdminDashboard({ stats, blockedCount, chart }) {
    // Math.max(1, ...) — чтобы не делить на ноль, если новых пользователей не было совсем.
    const maxCount = Math.max(1, ...chart.map((item) => item.count));

    return (
        <AdminLayout title="Панель мониторинга" subtitle="Добро пожаловать в админ-панель тревелвей.">
            <section className="admin-stats-row">
                <StatCard label="Пользователи" stat={stats.users} accentClass="text-green" />
                <StatCard label="Путешествия" stat={stats.trips} accentClass="text-orange" />
                <StatCard label="Места" stat={stats.places} />
            </section>

            <section className="admin-chart-card">
                <div className="admin-chart__header">
                    <h3 className="admin-chart__title">Рост пользователей</h3>
                    <div className="admin-chart__legend">
                        <span className="legend-dot" /> Новые
                    </div>
                </div>

                <div className="admin-chart__canvas">
                    <div className="chart-bars">
                        {chart.map((item) => (
                            <div key={item.month} className="chart-bar-item" title={`${item.month}: ${item.count}`}>
                                <span className="bar-label">{item.count}</span>
                                {/* Минимум 2px, чтобы и пустой месяц был виден на графике. */}
                                <div className="bar" style={{ height: Math.max(2, Math.round((item.count / maxCount) * MAX_BAR_HEIGHT)) }} />
                                <span className="bar-label">{item.month}</span>
                            </div>
                        ))}
                    </div>
                </div>
            </section>

            <section className="admin-quick-actions">
                <h3 className="quick-actions__title">Быстрые действия</h3>
                <div className="quick-actions__row">
                    <Link href="/admin/users" className="quick-action-card quick-action-card--primary">
                        <span className="qa-title">Управление пользователями</span>
                        <span className="qa-desc">Поиск, роли, блокировка и удаление</span>
                    </Link>
                    <Link href="/admin/users" className="quick-action-card">
                        <span className="qa-title">Заблокированные</span>
                        <span className="qa-desc">
                            {blockedCount === 0
                                ? 'Заблокированных нет'
                                : `${blockedCount} ${plural(blockedCount, ['аккаунт', 'аккаунта', 'аккаунтов'])}`}
                        </span>
                    </Link>
                </div>
            </section>
        </AdminLayout>
    );
}
