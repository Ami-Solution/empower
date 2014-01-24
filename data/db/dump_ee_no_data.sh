FILE_NAME=`date +%Y%m%d-%H%M%S`-db
sudo -u postgres pg_dump -s --no-tablespaces -O -t client -t consumption -t meter_load -t staging_* ee > $FILE_NAME.sql
zip $FILE_NAME.zip $FILE_NAME.sql
rm $FILE_NAME.sql

