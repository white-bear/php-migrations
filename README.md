php-migrations
==============

Миграции для MySql. Используется набор функций mysqli` для работы с базой данных, поскольку PDO не бросает исключения при исполнении некорректного SQL.
Перед первым запуском необходимо создать базу данных

    CREATE DATABASE `some_name` CHARSET=UTF8

Затем выполнить создание таблицы с историей миграций

    php migrate.php --init

После этого можно выполнить все миграции

    php migrate.php --up

Либо отдельную миграцию

    php migrate.php --up=<migration_id> --force

Либо откатить последнюю миграцию

    php migrate.php --down

Либо откатить отдельную миграцию

    php migrate.php --down=<migration_id> --force

Чтобы создать новый файл с миграцией, необходимо выполнить команду

    php migrate.php --generate='<migration_name>'

Файл с новой миграцией будет размещен в директории `schema`
