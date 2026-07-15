# Отключение доменной авторизации Bitrix24 для пользователей NLE

## Выбранный путь

Используем точечную защиту на Apache/PHP: если NTLM уже определил пользователя как `NLE\\...`, перед стартом Bitrix24 очищаем серверные признаки доменной авторизации. После этого Bitrix24 не должен воспринимать запрос как SSO-вход и должен показать обычную форму логина.

Такой вариант не меняет поведение для `NSC\\...`: NTLM продолжает работать, а PHP-фильтр ничего не очищает.

## Что добавить в текущий `nltm_srv-off-btrx02.nsc.ru.conf`

В обоих блоках `<Directory /home/bitrix/www/>` — для `*:8890` и для `*:8891` — после строк с `php_admin_value session.save_path` и `php_admin_value upload_tmp_dir` добавьте:

```apache
php_admin_value auto_prepend_file /home/bitrix/www/local/php_interface/nle_disable_ntlm.php
```

Итоговый фрагмент внутри каждого `<Directory /home/bitrix/www/>` должен выглядеть так:

```apache
php_admin_value session.save_path /tmp/php_sessions/www
php_admin_value upload_tmp_dir /tmp/php_upload/www
php_admin_value auto_prepend_file /home/bitrix/www/local/php_interface/nle_disable_ntlm.php
```

Файл `php/nle_disable_ntlm.php` нужно положить на сервере в путь:

```text
/home/bitrix/www/local/php_interface/nle_disable_ntlm.php
```

## Проверка после установки

1. Выполнить `apachectl -t` или `httpd -t`.
2. Перезапустить Apache/httpd.
3. Войти с терминального сервера под `NSC\\user` — автоматический вход должен сохраниться.
4. Войти с терминального сервера под `NLE\\user` — Bitrix24 должен открыть форму логина без ожидания 300 секунд.
5. Если зависание останется, пришлите значение `REMOTE_USER` из access/debug-лога для NLE-входа: возможно, модуль NTLM отдает формат не `NLE\\user`, а `user@NLE` или NetBIOS/UPN другого вида.
