"""Check production migration CLI on a disposable database only."""
import hashlib,os,shutil,sqlite3,subprocess,tempfile
from pathlib import Path
root=Path(__file__).resolve().parents[1]
source=Path('/workspace/gestisser-env/logs/initial-test-artifacts/database.sqlite')
original=hashlib.sha256(source.read_bytes()).hexdigest()
php=os.environ.get('SALES_TEST_PHP','/workspace/gestisser-env/bin/php')
with tempfile.TemporaryDirectory(prefix='sales-production-cli-') as folder:
    db=Path(folder)/'database.sqlite';shutil.copyfile(source,db)
    env=dict(os.environ,APP_ENV='production',GESTISSER_DB_PATH=str(db))
    before=hashlib.sha256(db.read_bytes()).hexdigest()
    command=[php,str(root/'scripts/migrate_sales_orders.php')]
    denied=subprocess.run(command,env=env,capture_output=True)
    assert denied.returncode==1 and hashlib.sha256(db.read_bytes()).hexdigest()==before
    c=sqlite3.connect(db)
    tables=['erp_customers','erp_finished_products','erp_production_orders','erp_stock_movements']
    rows={table:c.execute('SELECT * FROM '+table+' ORDER BY id').fetchall() for table in tables};c.close()
    subprocess.run(command+['--activate-production'],env=env,check=True,capture_output=True)
    c=sqlite3.connect(db)
    assert c.execute('SELECT setting_value FROM app_settings WHERE setting_key="sales_orders_enabled"').fetchone()[0]=='1'
    assert 'requisition_number' in [row[1] for row in c.execute('PRAGMA table_info(erp_sales_orders)')]
    for table in tables:assert c.execute('SELECT * FROM '+table+' ORDER BY id').fetchall()==rows[table]
    c.execute('UPDATE erp_number_sequences SET next_number=125 WHERE code="sales_order"');c.commit();c.close()
    subprocess.run(command+['--activate-production'],env=env,check=True,capture_output=True)
    c=sqlite3.connect(db);assert c.execute('SELECT next_number FROM erp_number_sequences WHERE code="sales_order"').fetchone()[0]==125;c.close()
assert hashlib.sha256(source.read_bytes()).hexdigest()==original
print('PASS: production CLI requires explicit flag, preserves customers/articles/OF/stock, activates schema, and keeps existing counters on repeated activation.')
