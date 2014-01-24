sudo -u postgres psql -c "DROP TABLE distribution_area CASCADE" ee

cd ../shp/

/usr/lib/postgresql/9.2/bin/shp2pgsql -I -s 4283 distribution_areas.shp distribution_area ee | sudo -u postgres psql -d ee

