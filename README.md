# TikTok Shop XML zu Shopware 5 Sychronisation

## Überblick

Diese Web-Anwendung wurde als individuelle Lösung für die **Kräuterland Natur-Ölmühle GmbH** ([www.kraeuterland.de](https://www.kraeuterland.de)) von der **JUNU Marketing Group** ([ju.nu](https://ju.nu)) entwickelt. Sie dient dazu, Bestellungen aus dem TikTok Shop automatisch in das Shopware 5 System zu importieren. Das Projekt verarbeitet CSV-Dateien, die vom TikTok Shop exportiert werden, und erstellt daraus Bestellungen sowie Gastkunden in Shopware über die Shopware 5 API.

## Funktionalitäten

### Hauptfunktionen
- **CSV-Verarbeitung:** Liest TikTok Shop-Bestellungen aus CSV-Dateien und gruppiert sie nach `OrderID`.
- **Gastkunden-Erstellung:** Erstellt oder findet bestehende Gastkunden basierend auf der E-Mail-Adresse und fügt Telefonnummern (im Format `01746688752`) hinzu.
- **Bestellungsimport:** 
  - Importiert Bestellungen mit `OriginalShippingFee` als Versandkosten.
  - Fügt Rabatte als separate Positionen hinzu: Verkäuferrabatt, TikTok Shop-Rabatte auf Artikel und TikTok Shop-Versandrabatte (steuerfrei mit `taxId=5`, `taxRate=0%`).
  - Setzt `CreatedTime` als `orderTime` und `PaidTime` als `clearedDate`.
  - Berechnet Nettobeträge (`invoiceAmountNet`, `invoiceShippingNet`) mit einem Standardsteuersatz von 7% (`taxId=4`).
- **Duplikatsprüfung:** Prüft anhand des `internalComment` (`TikTok Order ID: <orderId>`), ob eine Bestellung bereits existiert.

### Technische Details
- **Programmiersprache:** PHP
- **Abhängigkeiten:** 
  - GuzzleHttp für API-Anfragen
  - PSR-Log für Logging
- **API:** Shopware 5 REST API (`https://www.kraeuterland.de/api`)
- **Dateistruktur:**
  - `src/CsvProcessor.php`: Hauptlogik für CSV-Verarbeitung und Bestellungsimport.
  - `src/ShopwareClient.php`: API-Client für Shopware 5 mit Retry-Logik.
  - `src/Worker.php`: Einstiegspunkt zum Ausführen der Synchronisation.
  - `.env`: Konfigurationsdatei für API-Zugangsdaten und Einstellungen.
  - `storage/logs/app.log`: Log-Datei für Debugging und Fehlerverfolgung.

## Installation

### Voraussetzungen
- PHP >= 7.4
- Composer für Abhängigkeitsverwaltung
- Shopware 5 Instanz mit API-Zugriff
- Supervisor (für Hintergrundprozesse)

### Schritte
1. **Repository klonen:**
   ```bash
   git clone <repository-url>
   cd tiktoksync
   ```

2. **Abhängigkeiten installieren:**
   ```bash
   composer install
   ```

3. **Konfiguration erstellen:**
   - Kopiere `.env.example` nach `.env` und passe die Werte an:
     ```env
     SHOPWARE_API_URL=https://www.kraeuterland.de/api
     SHOPWARE_API_USERNAME=<your-username>
     SHOPWARE_API_KEY=<your-api-key>
     SHOPWARE_PAYMENT_METHOD_ID=123
     SHOPWARE_COUNTRY_ID=2
     SHOPWARE_SHIPPING_METHOD_ID=26
     SHOPWARE_SHOP_ID=1
     ```
   - Ersetze die Platzhalter mit den tatsächlichen Shopware-API-Daten.

4. **Log-Verzeichnis erstellen:**
   ```bash
   mkdir -p storage/logs
   chmod 775 storage/logs
   ```

5. **Supervisor konfigurieren:**
   - Erstelle eine Supervisor-Konfigurationsdatei (z.B. `/etc/supervisor/conf.d/tiktok_worker.conf`):
     ```ini
     [program:tiktok_worker]
     command=php /home/runcloud/webapps/tiktoksync/src/Worker.php
     directory=/home/runcloud/webapps/tiktoksync
     autostart=true
     autorestart=true
     redirect_stderr=true
     stdout_logfile=/home/runcloud/webapps/tiktoksync/storage/logs/supervisor.log
     ```
   - Lade die Konfiguration neu und starte den Worker:
     ```bash
     sudo supervisorctl reread
     sudo supervisorctl update
     sudo supervisorctl start tiktok_worker
     ```

## Nutzung

1. **CSV-Datei bereitstellen:**
   - Lade die TikTok Shop-Bestell-CSV-Datei in ein überwaches Verzeichnis hoch (z.B. `/home/runcloud/webapps/tiktoksync/input/`).
   - Die CSV muss die folgenden Spalten enthalten: `OrderID`, `SellerSKU`, `SKUUnitOriginalPrice`, `SKUSellerDiscount`, `SKUPlatformDiscount`, `OriginalShippingFee`, `ShippingFeePlatformDiscount`, `OrderAmount`, `CreatedTime`, `PaidTime`, `Recipient`, `Email`, `Phone#`, etc.

2. **Worker ausführen:**
   - Der Supervisor-Worker (`Worker.php`) verarbeitet die CSV-Dateien automatisch. Alternativ manuell starten:
     ```bash
     php /home/runcloud/webapps/tiktoksync/src/Worker.php
     ```

3. **Logs überprüfen:**
   - Überwache den Fortschritt und Fehler in `storage/logs/app.log`.

## Beispiel-CSV-Format
```csv
OrderID,SellerSKU,SKUUnitOriginalPrice,SKUSellerDiscount,SKUPlatformDiscount,OriginalShippingFee,ShippingFeePlatformDiscount,OrderAmount,CreatedTime,PaidTime,Recipient,Email,Phone#
576738386275441382,300053,"25,90 EUR","-3,89 EUR","-1,67 EUR","4,90 EUR","-4,90 EUR","72,98 EUR","04/08/2025 6:54:12 AM","04/08/2025 6:55:16 AM","Lydia koch","banymaus@web.de","(+49)1746688752"
```

## Projektstruktur
- **`src/CsvProcessor.php`:** Verarbeitet CSV-Dateien, erstellt Gastkunden und Bestellungen.
  - `processFile`: Liest und gruppiert CSV-Daten.
  - `createShopwareOrder`: Erstellt Bestellungen mit Rabattpositionen.
  - `createGuestCustomer`: Verwaltet Gastkunden mit Telefonnummern.
- **`src/ShopwareClient.php`:** API-Client mit Methoden für `get`, `post`, und `createOrder`.
- **`src/Worker.php`:** Einstiegspunkt für die Hintergrundverarbeitung (nicht detailliert gezeigt, aber typischerweise ein Datei-Watcher).

## Konfiguration
Die `.env`-Datei enthält:
- `SHOPWARE_API_URL`: Basis-URL der Shopware-API.
- `SHOPWARE_API_USERNAME` & `SHOPWARE_API_KEY`: API-Zugangsdaten.
- `SHOPWARE_PAYMENT_METHOD_ID`: Zahlungsmethode-ID.
- `SHOPWARE_COUNTRY_ID`: Länder-ID (z.B. 2 für Deutschland).
- `SHOPWARE_SHIPPING_METHOD_ID`: Versandmethode-ID.
- `SHOPWARE_SHOP_ID`: Shop-ID (default: 1).

## Fehlerbehandlung
- Logs werden in `storage/logs/app.log` geschrieben (INFO, WARNING, ERROR).
- Bei API-Fehlern (z.B. `400 Bad Request`) wird der Fehler protokolliert.

## Entwicklerhinweise
- **Steuersätze:** Standard 7% (`taxId=4`) für Artikel, 0% (`taxId=5`) für Versandrabatte.
- **Zeitformat:** `CreatedTime` und `PaidTime` werden in `Y-m-d H:i:s` umgewandelt.
- **Telefonnummern:** `(+49)` wird durch `0` ersetzt (z.B. `(+49)1746688752` → `01746688752`).

## Kontakt
Entwickelt von **JUNU Marketing Group** ([ju.nu](https://ju.nu)) für **Kräuterland Natur-Ölmühle GmbH** ([www.kraeuterland.de](https://www.kraeuterland.de)). Bei Fragen wenden Sie sich an JUNU Marketing Group.