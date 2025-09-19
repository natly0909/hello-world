#!/bin/env bash
#Автор Dmytro Savchenko  savchenkod19@gmail.com

# додам кольори щоб красиво було
RED="\e[31m"
GREEN="\e[32m"
YELLOW="\e[33m"
RESET="\e[0m"

#Перевірка чи можна перевантажувати nginx. Якщо ні, веб сервер не запрацює, всі сайти впадуть
nginx_status=$( nginx -t 2>&1 |grep -c  "failed")

if [[ $nginx_status -ne 0 ]]; then
  echo -e "${RED}nginx має помилки, встановлення сертифікату неможливо${RESET}"
  exit 1;
fi
#Встановлення certbot та плагіну для роботи з nginx. У Centos 7 є часта проблема, що репозиторії вже не працюють. Посилання на статтю 2025 року
yum install certbot python2-certbot-nginx -y
if [[ $? -ne 0 ]]; then
  echo -e "${RED}помилка встановлення certbot. Можливо проблема з репозиторіями. Гляньте тут вирішення https://www.veeble.com/kb/centos-repo-list-working-urls/${RESET}"
  exit 1;
fi

#Забираємо дані для підстановки їх в команду certbot-та для випуску сертифікату для домену з плагіном nginx. Руками сертифікати не прописуємо
echo -e "${GREEN}Вкажіть домен. Приклад domain.com:${RESET} "
read   domain
echo -e "${GREEN}Вкажіть домен з www. Якщо немає, залиште пустим:${RESET} "
read   wwwdomain

if [[ -z $wwwdomain ]]; then
  certbot --nginx -d "$domain"  --no-redirect --register-unsafely-without-email --agree-tos
else
  certbot --nginx -d "$domain" -d "$wwwdomain"   --no-redirect --register-unsafely-without-email --agree-tos
fi

if [[  $? -ne 0 ]]; then
  echo -e "${RED}Помилка встановлення сертифікату.${RESET}"
  exit 1;
fi

#Перевірка чи пройде успішно перевипуск сертифікату автоматично
echo -e "${GREEN}Перевірка автооновлення${RESET}"
certbot renew --dry-run

if [[ $? -ne 0 ]]; then
  echo -e "${RED}Помилка перевірки автооновлення сертифікату.${RESET}"
  exit 1;
fi

#Коментування крону для dehydrated. Запуск крону від рута, у файл можна покласти виконуваний код який виконається
cron_file=/etc/crontab
if [[ -s "$cron_file" ]]; then
  is_comment=$(grep -cE '#+.*root /opt/webdir/bin/bx-dehydrated' /etc/crontab)
  if [[ $is_comment -ne 0 ]]; then
    echo -e "${GREEN}Крон для dehydrated закоментовано:\n${RESET}${YELLOW}$(cat "$cron_file")${RESET}"
  else
    echo -e "${YELLOW}Коментування bx-dehydrated в /etc/crontab${RESET}"
    sed -i '/0 2 \* \* 6 root \/opt\/webdir\/bin\/bx-dehydrated/s/^/#/' /etc/crontab
  fi
else
  echo -e "${YELLOW}Відсутній або пустий файл /etc/crontab${RESET}"
fi

if [[ $? -ne 0 ]]; then
  echo -e "${RED}Помилка коментування  bx-dehydrated${RESET}"
  exit 1;
fi

#Переваірка чи є у нас автоматичне оновлення сертифікатів, якщо немає, добавляємо
certbot_cron=/etc/cron.d/certbot_tucha

if [[ ! -f $certbot_cron ]]; then
   tee ${certbot_cron} > /dev/null <<'EOF'
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/sbin:/bin:/usr/sbin:/usr/bin
0 */12 * * * root certbot -q renew --nginx
EOF
   chmod 0644 ${certbot_cron}
   if [[ $? -ne 0 ]]; then
     echo -e "${RED}Помилка перевірки/встановлення автоматичного оновлення сертифікату.${RESET}"
     exit 1;
    fi

   echo -e "${GREEN}Встановлення крону для автооновлення сертифікату успішно виконано.${RESET}"

else
  echo -e "${GREEN}Файл автооновлення certbot-ом присутній. Його вміст:${RESET}\n${YELLOW}$(cat "$certbot_cron")${RESET}"
fi

echo -e "${GREEN}\nЮху, встановлення сертифікату, коментування крону, встановлення крону для автооновлення сертифікату успішно завершено.${RESET}\n"

