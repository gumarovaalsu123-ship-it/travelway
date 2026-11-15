import React, { useRef, useState } from 'react';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import AppLayout from '../../Components/AppLayout';
import FormField from '../../Components/Auth/FormField';
import Avatar from '../../Components/Profile/Avatar';

/**
 * Аватар: файл отправляется сразу после выбора, отдельно от формы с именем и почтой.
 */
function AvatarEditor({ profile }) {
    const { errors } = usePage().props;
    const fileInput = useRef(null);
    const [isUploading, setUploading] = useState(false);

    const upload = (event) => {
        const file = event.target.files[0];

        if (!file) {
            return;
        }

        // Объект с файлом Inertia сам отправит как multipart/form-data.
        router.post('/profile/avatar', { avatar: file }, {
            preserveScroll: true,
            onStart: () => setUploading(true),
            onFinish: () => {
                setUploading(false);
                // Сбрасываем выбор, чтобы тот же файл можно было выбрать ещё раз.
                event.target.value = '';
            },
        });
    };

    const remove = () => {
        if (window.confirm('Удалить аватар?')) {
            router.delete('/profile/avatar', { preserveScroll: true });
        }
    };

    return (
        <div className={`avatar-edit-row mb-24 ${errors.avatar ? 'has-error' : ''}`}>
            <Avatar avatarUrl={profile.avatarUrl} initials={profile.initials} className="avatar-edit-circle" />
            <div className="avatar-edit-info">
                <span className="avatar-edit-label">Аватар</span>
                <span className="avatar-edit-hint">
                    {profile.avatarUrl ? 'Ваша фотография' : 'Инициалы вашего аккаунта'} · JPG, PNG или WEBP, до 2 МБ
                </span>
                <div className="avatar-edit-actions">
                    <input
                        ref={fileInput}
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        hidden
                        onChange={upload}
                    />
                    <button
                        type="button"
                        className="btn btn--outline-dark btn--sm"
                        disabled={isUploading}
                        onClick={() => fileInput.current.click()}
                    >
                        {isUploading ? 'Загрузка…' : 'Загрузить фото'}
                    </button>
                    {profile.avatarUrl && (
                        <button type="button" className="btn btn--danger-outline btn--sm" onClick={remove}>
                            Удалить
                        </button>
                    )}
                </div>
                {errors.avatar && <span className="form-error">{errors.avatar}</span>}
            </div>
        </div>
    );
}

/**
 * Смена пароля: блок раскрывается по кнопке «Изменить пароль».
 */
function PasswordSection() {
    const [isOpen, setOpen] = useState(false);
    const { data, setData, put, processing, errors, reset } = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });

    const submit = (event) => {
        event.preventDefault();
        put('/profile/password', {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
            // Пароли после неудачной попытки не держим в форме.
            onError: () => reset('password', 'password_confirmation'),
        });
    };

    if (!isOpen) {
        return (
            <div className="account-data-row">
                <div className="account-data-info">
                    <span className="account-data-label">Пароль</span>
                    <span className="account-data-hint profile-password-dots">••••••••••••</span>
                </div>
                <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => setOpen(true)}>
                    Изменить пароль
                </button>
            </div>
        );
    }

    return (
        <form onSubmit={submit} noValidate className="profile-password-form">
            <span className="account-data-label">Смена пароля</span>
            <FormField
                id="current_password"
                label="Текущий пароль"
                type="password"
                autoComplete="current-password"
                value={data.current_password}
                onChange={(value) => setData('current_password', value)}
                error={errors.current_password}
            />
            <div className="form-row">
                <FormField
                    id="password"
                    label="Новый пароль"
                    type="password"
                    placeholder="Не менее 8 символов"
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(value) => setData('password', value)}
                    error={errors.password}
                />
                <FormField
                    id="password_confirmation"
                    label="Повторите пароль"
                    type="password"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(value) => setData('password_confirmation', value)}
                />
            </div>
            <div className="form-actions form-actions--end">
                <button type="button" className="btn btn--outline-dark btn--sm" onClick={() => { reset(); setOpen(false); }}>
                    Отмена
                </button>
                <button type="submit" className="btn btn--solid-dark btn--sm" disabled={processing}>
                    Сменить пароль
                </button>
            </div>
        </form>
    );
}

export default function ProfileEdit({ profile }) {
    const { data, setData, put, processing, errors } = useForm({
        first_name: profile.firstName,
        last_name: profile.lastName,
        email: profile.email,
    });

    const submit = (event) => {
        event.preventDefault();
        put('/profile');
    };

    return (
        <AppLayout title="Редактировать профиль">
            <main className="container edit-profile-main mt-32 mb-40">
                <div className="settings-wrapper">
                    <Link href="/profile" className="back-link mb-24">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
                            <path d="M19 12H5M12 19l-7-7 7-7" />
                        </svg>
                        Вернуться в профиль
                    </Link>

                    <header className="page-header mb-32" style={{ alignItems: 'flex-start' }}>
                        <h1 className="page-title">Редактировать профиль</h1>
                    </header>

                    <section className="form-card mb-24">
                        <h2 className="form-section-title">Основные данные</h2>
                        <AvatarEditor profile={profile} />

                        <form id="profile-form" className="edit-profile-form" onSubmit={submit} noValidate>
                            <div className="form-row mb-16">
                                <FormField
                                    id="first_name"
                                    label="Имя"
                                    autoComplete="given-name"
                                    value={data.first_name}
                                    onChange={(value) => setData('first_name', value)}
                                    error={errors.first_name}
                                />
                                <FormField
                                    id="last_name"
                                    label="Фамилия"
                                    autoComplete="family-name"
                                    value={data.last_name}
                                    onChange={(value) => setData('last_name', value)}
                                    error={errors.last_name}
                                />
                            </div>
                            <FormField
                                id="email"
                                label="Электронная почта"
                                type="email"
                                autoComplete="email"
                                value={data.email}
                                onChange={(value) => setData('email', value)}
                                error={errors.email}
                            />
                        </form>
                    </section>

                    <section className="form-card mb-24">
                        <h2 className="form-section-title">Данные аккаунта</h2>
                        <PasswordSection />
                    </section>

                    {/* Кнопка снаружи формы связана с ней атрибутом form="profile-form". */}
                    <div className="form-actions form-actions--end">
                        <Link href="/profile" className="btn btn--outline-dark">Отмена</Link>
                        <button type="submit" form="profile-form" className="btn btn--solid-dark" disabled={processing}>
                            Сохранить изменения
                        </button>
                    </div>
                </div>
            </main>
        </AppLayout>
    );
}
