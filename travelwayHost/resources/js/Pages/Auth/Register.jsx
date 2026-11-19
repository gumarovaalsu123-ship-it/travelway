import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../Components/Auth/AuthLayout';
import FormField from '../../Components/Auth/FormField';

export default function Register() {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        email: '',
        password: '',
        password_confirmation: '',
        terms: false,
    });

    const submit = (event) => {
        event.preventDefault();
        post('/register', { onFinish: () => setData((current) => ({ ...current, password: '', password_confirmation: '' })) });
    };

    return (
        <AuthLayout
            title="Регистрация | Тревелвей"
            heroImage="/images/reg-hero.png"
            quote="Планируйте поездки с картой, сохраняйте любимые места и делитесь готовым маршрутом по ссылке."
        >
            <h1 className="auth-title">Создать аккаунт</h1>
            <p className="auth-subtitle">Откройте для себя новые возможности планирования отдыха</p>

            <form className="auth-form" onSubmit={submit} noValidate>
                <div className="form-row">
                    <FormField
                        id="first_name"
                        label="Имя"
                        placeholder="Иван"
                        autoComplete="given-name"
                        value={data.first_name}
                        onChange={(value) => setData('first_name', value)}
                        error={errors.first_name}
                        required
                    />
                    <FormField
                        id="last_name"
                        label="Фамилия"
                        placeholder="Иванов"
                        autoComplete="family-name"
                        value={data.last_name}
                        onChange={(value) => setData('last_name', value)}
                        error={errors.last_name}
                        required
                    />
                </div>

                <FormField
                    id="email"
                    label="Электронная почта"
                    type="email"
                    placeholder="example@mail.ru"
                    autoComplete="email"
                    value={data.email}
                    onChange={(value) => setData('email', value)}
                    error={errors.email}
                    required
                />

                <FormField
                    id="password"
                    label="Пароль"
                    type="password"
                    placeholder="Минимум 8 символов"
                    autoComplete="new-password"
                    value={data.password}
                    onChange={(value) => setData('password', value)}
                    error={errors.password}
                    required
                />

                <FormField
                    id="password_confirmation"
                    label="Подтверждение пароля"
                    type="password"
                    placeholder="Повторите пароль"
                    autoComplete="new-password"
                    value={data.password_confirmation}
                    onChange={(value) => setData('password_confirmation', value)}
                    required
                />

                <div className={`form-options ${errors.terms ? 'has-error' : ''}`}>
                    <label className="custom-checkbox">
                        <input
                            type="checkbox"
                            className="custom-checkbox__input"
                            checked={data.terms}
                            onChange={(event) => setData('terms', event.target.checked)}
                        />
                        <span className="custom-checkbox__box">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                        </span>
                        <span className="custom-checkbox__label">Я согласен с Условиями использования и Политикой конфиденциальности</span>
                    </label>
                </div>
                {errors.terms && <span className="form-error">{errors.terms}</span>}

                <button type="submit" className="btn btn--solid btn--full" disabled={processing}>
                    {processing ? 'Создаём…' : 'Создать аккаунт'}
                </button>
            </form>

            <div className="auth-footer">
                Уже есть аккаунт? <Link href="/login">Войти</Link>
            </div>
        </AuthLayout>
    );
}
