import React from 'react';
import { Head, Link, usePage } from '@inertiajs/react';
import Footer from '../Components/Footer';
import { plural } from '../utils/format';

const FEATURES = [
    {
        title: 'Планирование маршрута',
        text: 'Удобный таймлайн по дням: отмечайте время, добавляйте описание и локации без ограничений.',
        icon: <svg width="17" height="18" viewBox="0 0 17 18" fill="none"><path d="M4.95833 1V2.58346M4.95833 2.58346V4.16692M4.95833 2.58346H2.58333C1.70888 2.58346 1 3.2924 1 4.16692V7.33384M4.95833 2.58346H11.2917M11.2917 1V2.58346M11.2917 2.58346V4.16692M11.2917 2.58346H13.6667C14.5411 2.58346 15.25 3.2924 15.25 4.16692V7.33384M1 7.33384H15.25M1 7.33384V15.2511C1 16.1257 1.70888 16.8346 2.58333 16.8346H13.6667C14.5411 16.8346 15.25 16.1257 15.25 15.2511V7.33384" strokeWidth="2" strokeLinecap="round" stroke="white" /></svg>,
    },
    {
        title: 'Интерактивная карта',
        text: 'Все ваши точки автоматически наносятся на карту. Управляйте маршрутом наглядно.',
        icon: <svg width="17" height="16" viewBox="0 0 17 16" fill="none"><path d="M10.5 3.00123C10.2543 3.00123 10.012 2.94406 9.79225 2.83424L6.45775 1.16699C6.23799 1.05717 5.99568 1 5.75 1M10.5 3.00123C10.7457 3.00123 10.988 2.94406 11.2078 2.83424L14.1045 1.38549C14.2252 1.32513 14.3595 1.29667 14.4944 1.30281C14.6292 1.30896 14.7603 1.3495 14.8751 1.42058C14.9899 1.49167 15.0846 1.59094 15.1502 1.70895C15.2159 1.82696 15.2502 1.95979 15.25 2.09482V12.1997C15.2499 12.3466 15.2089 12.4907 15.1316 12.6157C15.0543 12.7407 14.9437 12.8417 14.8122 12.9074L11.2078 14.71C10.988 14.8198 10.7457 14.877 10.5 14.877C10.2543 14.877 10.012 14.8198 9.79225 14.71L6.45775 13.0428C6.23799 12.933 5.99568 12.8758 5.75 12.8758C5.50433 12.8758 5.26202 12.933 5.04225 13.0428L2.14554 14.4915C2.0247 14.5519 1.89041 14.5804 1.75546 14.5742C1.6205 14.568 1.48938 14.5274 1.37455 14.4562C1.25973 14.385 1.16503 14.2857 1.09947 14.1675C1.03391 14.0494 0.999669 13.9165 1 13.7814V3.67736C1.00008 3.53038 1.04108 3.38632 1.1184 3.26132C1.19572 3.13632 1.30631 3.03531 1.43779 2.96961L5.04225 1.16699C5.26202 1.05717 5.50433 1 5.75 1M10.5 3.00123L10.5 14.8758M5.75 1L5.75 12.875" strokeWidth="2" strokeLinecap="round" stroke="white" /></svg>,
    },
    {
        title: 'Транспорт и проживание',
        text: 'Храните все билеты, брони отелей и бронирования авто в одном безопасном архиве.',
        icon: <svg width="17" height="18" viewBox="0 0 17 18" fill="none"><path d="M10.5 16.0417V9.70833C10.5 9.49837 10.4166 9.29701 10.2681 9.14854C10.1197 9.00007 9.9183 8.91667 9.70833 8.91667H6.54167C6.3317 8.91667 6.13034 9.00007 5.98187 9.14854C5.83341 9.29701 5.75 9.49837 5.75 9.70833V16.0417M1 7.33371C0.999945 7.10339 1.05014 6.87583 1.14708 6.66691C1.24402 6.45798 1.38538 6.27272 1.56129 6.12405L7.10296 1.37405C7.38874 1.13252 7.75082 1 8.125 1C8.49918 1 8.86126 1.13252 9.14704 1.37405L14.6887 6.12405C14.8646 6.27272 15.006 6.45798 15.1029 6.66691C15.1999 6.87583 15.2501 7.10339 15.25 7.33371V14.4587C15.25 14.8786 15.0832 15.2814 14.7863 15.5783C14.4893 15.8752 14.0866 16.042 13.6667 16.042H2.58333C2.16341 16.042 1.76068 15.8752 1.46375 15.5783C1.16681 15.2814 1 14.8786 1 14.4587V7.33371Z" strokeWidth="2" strokeLinecap="round" stroke="white" /></svg>,
    },
    {
        title: 'Список вещей',
        text: 'Умные чек-листы для сборов: готовые шаблоны и возможность создавать свои списки.',
        icon: <svg width="18" height="17" viewBox="0 0 18 17" fill="none"><path d="M15.25 7.061V13.6667C15.25 14.0866 15.0832 14.4893 14.7863 14.7863C14.4893 15.0832 14.0866 15.25 13.6667 15.25H2.58333C2.16341 15.25 1.76068 15.0832 1.46375 14.7863C1.16681 14.4893 1 14.0866 1 13.6667V2.58333C1 2.16341 1.16681 1.76068 1.46375 1.46375C1.76068 1.16681 2.16341 1 2.58333 1H12.3557M5.75 7.33333L8.125 9.70833L16.0417 1.79167" strokeWidth="2" strokeLinecap="round" stroke="white" /></svg>,
    },
    {
        title: 'Избранные места',
        text: 'Сохраняйте кафе, парки и музеи в личный кабинет, чтобы вернуться к ним при планировании.',
        icon: <svg width="18" height="16" viewBox="0 0 18 16" fill="none"><path d="M1 5.367C1.00002 4.48602 1.26728 3.62578 1.7665 2.89987C2.26571 2.17397 2.97339 1.61657 3.79607 1.30127C4.61875 0.985982 5.51772 0.927639 6.37426 1.13395C7.23079 1.34026 8.00459 1.80152 8.59346 2.4568C8.63493 2.50115 8.68508 2.5365 8.74078 2.56067C8.79648 2.58484 8.85655 2.59731 8.91728 2.59731C8.978 2.59731 9.03807 2.58484 9.09377 2.56067C9.14947 2.5365 9.19962 2.50115 9.24109 2.4568C9.82811 1.79726 10.6021 1.33212 11.46 1.12331C12.3179 0.91449 13.2191 0.971896 14.0436 1.28788C14.8681 1.60387 15.5768 2.16345 16.0754 2.89215C16.574 3.62084 16.8388 4.48409 16.8346 5.367C16.8346 7.17993 15.647 8.53369 14.4594 9.7212L10.1112 13.9274C9.96368 14.0968 9.78179 14.2329 9.57761 14.3266C9.37344 14.4203 9.15166 14.4695 8.927 14.471C8.70235 14.4724 8.47996 14.426 8.27462 14.3349C8.06928 14.2437 7.88568 14.11 7.73602 13.9424L3.37518 9.7212C2.18759 8.53369 1 7.18785 1 5.367Z" strokeWidth="2" strokeLinecap="round" stroke="white" /></svg>,
    },
    {
        title: 'Публичная ссылка',
        text: 'Делитесь своим маршрутом публичной ссылкой и показывайте его другим без лишних действий.',
        icon: <svg width="17" height="17" viewBox="0 0 17 17" fill="none"><path d="M15.25 10.4998L12.8069 8.05668C12.51 7.75985 12.1073 7.5931 11.6875 7.5931C11.2677 7.5931 10.865 7.75985 10.5681 8.05668L3.375 15.2498M15.25 10.4998V2.58333C15.25 1.70888 14.5411 1 13.6667 1H2.58333C1.70888 1 1 1.70888 1 2.58333V13.6667C1 14.5411 1.70888 15.25 2.58333 15.25H13.6667C14.5411 15.25 15.25 14.5411 15.25 13.6667V10.4998ZM7.33333 5.75C7.33333 6.62445 6.62445 7.33333 5.75 7.33333C4.87555 7.33333 4.16667 6.62445 4.16667 5.75C4.16667 4.87555 4.87555 4.16667 5.75 4.16667C6.62445 4.16667 7.33333 4.87555 7.33333 5.75Z" strokeWidth="2" strokeLinecap="round" stroke="white" /></svg>,
    },
];

const STEPS = [
    { title: 'Создайте поездку', text: 'Выберите город или страну и укажите даты путешествия.' },
    { title: 'Наполните деталями', text: 'Добавляйте локации, время посещения, ссылки на бронирования и составляйте списки необходимых вещей.' },
    { title: 'Поделитесь маршрутом', text: 'Распространите готовый маршрут публичной ссылкой и покажите его другим без лишних действий.' },
];

const EXAMPLE_ROUTES = [
    { image: '/images/card1.png', tag: 'Город', status: 'Запланировано', statusClass: 'status-active', dates: '15 июня - 20 июня', places: 12, title: 'Солнечный Лиссабон и Синтра' },
    { image: '/images/card2.png', tag: 'Горы', status: 'В процессе', statusClass: 'status-process', dates: '1 июля - 10 июля', places: 8, title: 'Дикий Алтай без гидов' },
    { image: '/images/card3.png', tag: 'История', status: 'Завершено', statusClass: 'status-done', dates: '12 мая - 15 мая', places: 6, title: 'Выборгские легенды и парки' },
];

function LandingHeader({ user }) {
    return (
        <header className="header">
            <Link href="/" className="logo-group">
                <div className="logo-symbol"><img src="/images/logo.png" alt="" /></div>
                <div className="logo-text-group">
                    <span className="brand-name">тревелвей</span>
                    <span className="brand-tagline">планировщик</span>
                </div>
            </Link>
            <nav className="navigation-links">
                <a href="#features">Возможности</a>
                <a href="#how-it-works">Как это работает</a>
                <a href="#examples">Примеры</a>
            </nav>
            <div className="auth-actions">
                {user ? (
                    <>
                        <Link href="/interactive-map" className="btn btn--outline">Карта</Link>
                        <Link href="/my-trips" className="btn btn--solid">Мои путешествия</Link>
                    </>
                ) : (
                    <>
                        <Link href="/login" className="btn btn--outline">Войти</Link>
                        <Link href="/register" className="btn btn--solid">Создать аккаунт</Link>
                    </>
                )}
            </div>
        </header>
    );
}

export default function Home({ stats }) {
    const { auth } = usePage().props;
    const user = auth?.user;
    // Гостя кнопки ведут на регистрацию; после входа — сразу к созданию поездки.
    const startHref = user ? '/my-trips/create' : '/register';

    return (
        <div className="page-wrapper">
            <Head title="Тревелвей — планировщик путешествий" />

            <LandingHeader user={user} />

            <section className="hero">
                <div className="hero-info">
                    <div className="hero-headlines">
                        <h1 className="hero-title">Планируй поездки без хаоса</h1>
                        <p className="hero-desc">
                            Собери маршрут, карточки бронирований и чек-листы в одном месте. Без лишних вкладок, без суеты, без потери деталей.
                        </p>
                    </div>
                    <div className="hero-actions">
                        <Link href={startHref} className="btn btn--white">
                            <span className="btn-icon">＋</span> {user ? 'Создать путешествие' : 'Создать первое путешествие'}
                        </Link>
                        <a href="#how-it-works" className="btn btn--outline-white">
                            <span className="btn-icon">
                                <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                                    <path d="M11.83 6.42a5.42 5.42 0 1 1-10.83 0 5.42 5.42 0 0 1 10.83 0ZM8.71 4.12l-.97 2.93a1.08 1.08 0 0 1-.69.69l-2.93.97.98-2.93a1.08 1.08 0 0 1 .68-.69l2.93-.97Z" strokeWidth="2" strokeLinecap="round" stroke="white" />
                                </svg>
                            </span>
                            Как это работает
                        </a>
                    </div>
                </div>
                <div className="floating-card-wrapper">
                    <div className="floating-preview-card">
                        <div className="card-photo"><img src="/images/hero-card.png" alt="" /></div>
                        <div className="tag-badge">АКТИВНОЕ</div>
                        <div className="card-body">
                            <div className="card-title">Лиссабон · 5 дней</div>
                            <div className="card-dates">
                                <svg width="17" height="18" viewBox="0 0 17 18" fill="none">
                                    <path d="M4.95833 1V2.58346M4.95833 2.58346V4.16692M4.95833 2.58346H2.58333C1.70888 2.58346 1 3.2924 1 4.16692V7.33384M4.95833 2.58346H11.2917M11.2917 1V2.58346M11.2917 2.58346V4.16692M11.2917 2.58346H13.6667C14.5411 2.58346 15.25 3.2924 15.25 4.16692V7.33384M1 7.33384H15.25M1 7.33384V15.2511C1 16.1257 1.70888 16.8346 2.58333 16.8346H13.6667C14.5411 16.8346 15.25 16.1257 15.25 15.2511V7.33384" strokeWidth="2" strokeLinecap="round" stroke="#3D5A3E" />
                                </svg>
                                15 июня — 20 июня
                            </div>
                            <div className="card-line" />
                            <div className="card-places">
                                <svg width="17" height="18" viewBox="0 0 10 11" fill="none">
                                    <path d="M8.3326 4.58327C8.3326 6.87194 5.79415 9.25549 4.94173 9.99164C4.86232 10.0514 4.76566 10.0837 4.6663 10.0837C4.56694 10.0837 4.47028 10.0514 4.39087 9.99164C3.53845 9.25549 1 6.87194 1 4.58327C1 3.61072 1.38627 2.678 2.07383 1.9903C2.7614 1.3026 3.69394 0.91626 4.6663 0.91626C5.63866 0.91626 6.5712 1.3026 7.25877 1.9903C7.94633 2.678 8.3326 3.61072 8.3326 4.58327ZM6.04116 4.58327C6.04116 5.34273 5.42562 5.95839 4.6663 5.95839C3.90698 5.95839 3.29144 5.34273 3.29144 4.58327C3.29144 3.8238 3.90698 3.20814 4.6663 3.20814C5.42562 3.20814 6.04116 3.8238 6.04116 4.58327Z" strokeWidth="1" strokeLinecap="round" stroke="#3D5A3E" />
                                </svg>
                                12 мест запланировано
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section className="features-section" id="features">
                <div className="section-header">
                    <span className="section-subtitle">Возможности сервиса</span>
                    <h2 className="section-title">Всё для идеального путешествия</h2>
                    <p className="section-desc">Инструменты, которые помогают собрать яркие моменты без суеты</p>
                </div>
                <div className="features-grid">
                    {FEATURES.map((feature) => (
                        <div key={feature.title} className="feature-card">
                            <div className="feature-icon-container">{feature.icon}</div>
                            <div className="feature-text-group">
                                <h3 className="feature-name">{feature.title}</h3>
                                <p className="feature-details">{feature.text}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <section className="how-it-works" id="how-it-works">
                <div className="section-header">
                    <span className="section-subtitle">Простой процесс</span>
                    <h2 className="section-title">Три шага к началу приключения</h2>
                    <p className="section-desc">Мы свели планирование путешествий к трем понятным действиям</p>
                </div>
                <div className="steps-container">
                    {STEPS.map((step, index) => (
                        <div key={step.title} className="step-card">
                            <div className="number-bubble">{index + 1}</div>
                            <div className="feature-text-group">
                                <h3 className="feature-name">{step.title}</h3>
                                <p className="feature-details">{step.text}</p>
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <section className="personal-routes" id="examples">
                <div className="section-header">
                    <span className="section-subtitle">Примеры маршрутов</span>
                    <h2 className="section-title">Примеры планирования</h2>
                    <p className="section-desc">Иллюстрации того, как можно собрать места и даты в личный маршрут. Это не каталог чужих путешествий.</p>
                </div>
                <div className="route-row">
                    {EXAMPLE_ROUTES.map((route) => (
                        <div key={route.title} className="route-card">
                            <div className="route-image-wrapper" style={{ backgroundImage: `url('${route.image}')` }}>
                                <div className="route-tag">{route.tag}</div>
                                <div className={`route-status ${route.statusClass}`}>{route.status}</div>
                            </div>
                            <div className="route-content">
                                <div className="route-meta">
                                    <span className="route-duration">🕒 {route.dates}</span>
                                    <span className="route-places-count">{route.places} мест</span>
                                </div>
                                <h3 className="route-title">{route.title}</h3>
                                <div className="route-divider" />
                                <div className="route-action">Пример планирования <span>→</span></div>
                            </div>
                        </div>
                    ))}
                </div>
            </section>

            <section className="statistics">
                <div className="stat-block">
                    <span className="stat-number">{stats.trips}</span>
                    <span className="stat-label">{plural(stats.trips, ['спланированное путешествие', 'спланированных путешествия', 'спланированных путешествий'])}</span>
                </div>
                <div className="stat-block">
                    <span className="stat-number">{stats.places}</span>
                    <span className="stat-label">{plural(stats.places, ['интересное место добавлено', 'интересных места добавлено', 'интересных мест добавлено'])}</span>
                </div>
                <div className="stat-block">
                    <span className="stat-number">{stats.users}</span>
                    <span className="stat-label">{plural(stats.users, ['путешественник', 'путешественника', 'путешественников'])}</span>
                </div>
            </section>

            <section className="cta-section">
                <div className="cta-content">
                    <h2 className="cta-title">Начните планировать своё следующее приключение</h2>
                    <p className="cta-desc">
                        Создайте бесплатный аккаунт сегодня и соберите ваш первый маршрут за 10 минут. Тревелвей сделает всё просто и красиво.
                    </p>
                </div>
                <Link href={startHref} className="btn btn--white">
                    {user ? 'Создать путешествие' : 'Создать аккаунт бесплатно'}
                </Link>
            </section>

            <Footer />
        </div>
    );
}
