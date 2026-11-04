import React from 'react';
import { Link, useForm } from '@inertiajs/react';
import AuthLayout from '../../Components/Auth/AuthLayout';
import FormField from '../../Components/Auth/FormField';

export default function Login() {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
        password: '',
        remember: false,
    });

    const submit = (event) => {
        event.preventDefault();
        post('/login', { onFinish: () => setData('password', '') });
    };

    return (
        <AuthLayout
            title="Вход | Тревелвей"
            heroImage="/images/auth-hero.png"
            quote="«Поразительно, насколько проще стало планировать поездки с Тревелвей. Больше никакого хаоса в вкладках браузера.»"
            quoteAuthor="- Кристина В., путешественница"
        >
            <h1 className="auth-title">Рады возвращению!</h1>
            <p className="auth-subtitle">Войдите в свой аккаунт, чтобы продолжить планирование</p>

            <form className="auth-form" onSubmit={submit} noValidate>
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
                    placeholder="••••••••"
                    autoComplete="current-password"
                    value={data.password}
                    onChange={(value) => setData('password', value)}
                    error={errors.password}
                    required
                />

                <div className="form-options">
                    <label className="custom-checkbox">
                        <input
                            type="checkbox"
                            className="custom-checkbox__input"
                            checked={data.remember}
                            onChange={(event) => setData('remember', event.target.checked)}
                        />
                        <span className="custom-checkbox__box">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
                                <polyline points="20 6 9 17 4 12" />
                            </svg>
                        </span>
                        <span className="custom-checkbox__label">Запомнить меня</span>
                    </label>
                </div>

                <button type="submit" className="btn btn--solid btn--full" disabled={processing}>
                    {processing ? 'Входим…' : 'Войти'}
                </button>
            </form>

            <div className="auth-footer">
                Нет аккаунта? <Link href="/register">Создать аккаунт</Link>
            </div>
        </AuthLayout>
    );
}
