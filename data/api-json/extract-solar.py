import os, datetime
import urllib,urllib2
import json

# Retrieving today's date
dt = datetime.date.today()
url_json = "http://pv-map.apvi.org.au/data/"+dt.strftime('%Y-%m-%d')
print "Fetching:"+url_json
fn, d = urllib.urlretrieve(url_json,"now.json")
f = open(fn,'r')
json_data = json.load(f)
f.close()