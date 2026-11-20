import React, { useRef, useState } from 'react';
import { router, usePage } from '@inertiajs/react';
import { confirmDialog } from '../utils/confirm';

/**
 * Своё фото вместо автоматического (из Википедии): обложка поездки в «Настройках» и фото места в окне «Изменить».
 * Как и аватар, файл уходит на сервер сразу после выбора — отдельно от формы, кнопку «Сохранить» нажимать не нужно.
 *
 * Загруженные фото лежат у нас на сервере и начинаются с /storage/ (см. app/Services/PhotoStorage.php),
 * поэтому кнопку «вернуть автоматическое» показываем только для них.
 *
 * @param {{ label: string, imageUrl: ?string, fallbackUrl?: string, uploadUrl: string, resetLabel: string, resetQuestion: string }} props
 */
export default function PhotoPicker({ label, imageUrl, fallbackUrl, uploadUrl, resetLabel, resetQuestion }) {
    const { errors } = usePage().props;
    const fileInput = useRef(null);
    const [isUploading, setUploading] = useState(false);
    const isUploaded = Boolean(imageUrl?.startsWith('/storage/'));
    const preview = imageUrl ?? fallbackUrl;

    // preserveState — чтобы открытое окно «Изменить» не закрылось после загрузки, а просто показало новое фото.
    const options = { preserveScroll: true, preserveState: true };

    const upload = (event) => {
        const file = event.target.files[0];

        if (!file) {
            return;
        }

        // Объект с файлом Inertia сам отправит как multipart/form-data.
        router.post(uploadUrl, { photo: file }, {
            ...options,
            onStart: () => setUploading(true),
            onFinish: () => {
                setUploading(false);
                // Сбрасываем выбор, чтобы тот же файл можно было выбрать ещё раз.
                event.target.value = '';
            },
        });
    };

    const reset = async () => {
        if (await confirmDialog({ title: resetQuestion, confirmLabel: resetLabel })) {
            router.delete(uploadUrl, options);
        }
    };

    return (
        <div className={`form-group photo-picker ${errors.photo ? 'has-error' : ''}`}>
            <span className="form-label">{label}</span>
            <div className="photo-picker__row">
                <div
                    className={`photo-picker__preview ${preview ? '' : 'photo-picker__preview--empty'}`}
                    style={preview ? { backgroundImage: `url('${preview}')` } : undefined}
                />
                <div className="photo-picker__info">
                    <span className="form-hint">
                        {isUploaded ? 'Ваше фото' : 'Подобрано автоматически'} · JPG, PNG или WEBP, до 5 МБ
                    </span>
                    <div className="photo-picker__actions">
                        <input ref={fileInput} type="file" accept="image/jpeg,image/png,image/webp" hidden onChange={upload} />
                        <button
                            type="button"
                            className="btn btn--outline-dark btn--sm"
                            disabled={isUploading}
                            onClick={() => fileInput.current.click()}
                        >
                            {isUploading ? 'Загрузка…' : 'Загрузить своё'}
                        </button>
                        {isUploaded && (
                            <button type="button" className="btn btn--danger-outline btn--sm" onClick={reset}>{resetLabel}</button>
                        )}
                    </div>
                    {errors.photo && <span className="form-error">{errors.photo}</span>}
                </div>
            </div>
        </div>
    );
}
