import React from 'react';

/**
 * Аватар пользователя: картинка, а если её нет — инициалы («АВ»).
 * className задаёт размер и оформление круга (.profile-avatar-large или .avatar-edit-circle из app.css).
 */
export default function Avatar({ avatarUrl, initials, className }) {
    return (
        <div className={`${className} profile-avatar`}>
            {avatarUrl ? <img src={avatarUrl} alt="Аватар" /> : initials}
        </div>
    );
}
