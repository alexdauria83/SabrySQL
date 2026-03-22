# 🐬 SabrySQL
> Il client MySQL singolo file, veloce, bello e senza stronzate

![Version](https://img.shields.io/badge/versione-3.0-blue)
![PHP](https://img.shields.io/badge/PHP-%3E%3D7.4-777bb4)
![License](https://img.shields.io/badge/licenza-MIT-green)

SabrySQL è un'alternativa moderna, leggera e usabile a PhpMyAdmin e Adminer. Tutto in un singolo file, zero dipendenze, zero installazioni complicate.


---

## 📌 Perchè SabrySQL?
Sono stanco di:
- ❌ PhpMyAdmin che pesa 100MB, ha 1000 funzionalità che non userai mai ed è lentissimo
- ❌ Adminer che è orribile, scomodo e fermo a 10 anni fa
- ❌ Tutti gli altri client che ti mostrano 50 bottoni e quando li clicchi ti dicono che non hai i permessi
- ❌ Progetti che richiedono composer, npm, build e 1000 dipendenze per fare una cosa semplicissima

✅ SabrySQL fa esattamente quello che ti serve, niente di più niente di meno, in 1 singolo file da meno di 500KB.


---

## ✨ Caratteristiche principali

✅ **Singolo file**: copia `index.php` sul tuo server e hai finito
✅ Zero dipendenze, zero composer, zero build
✅ Multi connessione: configura quanti database vuoi e switcha tra di loro in un click
✅ Completamente consapevole dei permessi: mostra solo i pulsanti per le operazioni che puoi veramente fare
✅ DataGrid con colonne ridimensionabili a piacimento
✅ Modalità chiaro / scuro
✅ Modifica inline delle celle
✅ Generazione automatica INSERT / UPDATE / DELETE dalle righe selezionate
✅ Export CSV e SQL
✅ Visualizzatore e gestore processi con tasto Kill
✅ Creazione e modifica Viste
✅ Creazione Stored Procedure
✅ Designer tabelle visuale
✅ Resize di tutti i pannelli
✅ Filtro veloce su tutto l'albero
✅ Tasto Kill connessione: termina la sessione corrente di MySQL in un click


---

## 📦 Installazione

1. Scarica l'ultimo file `index.php` dalla pagina release
2. Crea un file `.env` nella stessa cartella
3. Configura le tue connessioni
4. Finito.

Niente altro. Non c'è nessun altro passaggio.


---

## ⚙️ Configurazione

Esempio file `.env`:

```env
# Connessione singola semplice
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USERNAME=root
DB_PASSWORD=tua_password
DB_LABEL=Locale

# Oppure connessioni multiple
DB_HOST_1=127.0.0.1
DB_PORT_1=3306
DB_USERNAME_1=root
DB_PASSWORD_1=password
DB_LABEL_1=Locale

DB_HOST_2=server.produzione.com
DB_PORT_2=3306
DB_USERNAME_2=utente
DB_PASSWORD_2=password_produzione
DB_LABEL_2=Produzione

DB_HOST_3=server.staging.com
DB_PORT_3=3306
DB_USERNAME_3=utente
DB_PASSWORD_3=password_staging
DB_LABEL_3=Staging
```

Puoi aggiungere fino a 99 connessioni.


---

## 🎯 Funzionalità uniche

| Funzionalità | SabrySQL | PhpMyAdmin | Adminer |
|---|---|---|---|
| Singolo file | ✅ | ❌ | ✅ |
| Multi connessione nativo | ✅ | ❌ | ❌ |
| Consapevole dei permessi | ✅ | ❌ | ❌ |
| Colonne ridimensionabili | ✅ | ❌ | ❌ |
| Tasto Kill connessione | ✅ | ❌ | ❌ |
| Generazione INSERT/UPDATE da selezione | ✅ | ❌ | ❌ |
| Modifica inline | ✅ | ✅ | ❌ |
| Tema scuro nativo | ✅ | ✅ | ❌ |
| Dimensione totale | 450KB | >100MB | 300KB |


---

## 🖼️ Screenshot

![Screenshot 1](https://i.imgur.com/9wZ7Q0Y.png)
![Screenshot 2](https://i.imgur.com/xKf7qLd.png)


---

## 🗺️ Roadmap

✅ Finito:
- ✅ Multi connessione
- ✅ Tema chiaro/scuro
- ✅ Resize colonne
- ✅ Permessi
- ✅ Viste
- ✅ Stored Procedure
- ✅ Kill connessione

🔜 In sviluppo:
- ⏳ Modifica chiavi esterne
- ⏳ Import SQL
- ⏳ Schedulo query
- ⏳ Storico query
- ⏳ Confronto tabelle


---

## 📝 Note

- Tutte le password sono salvate solo nel file `.env` e non vengono mai inviate al client
- Non c'è nessun tipo di telemetria, nessuna chiamata esterna, nessun codice nascosto
- Funziona perfettamente anche su qualsiasi hosting condiviso anche il più economico
- Testato su MySQL 5.7, 8.0 e MariaDB 10.x


---

## 📜 Licenza

MIT

Puoi fare quello che vuoi con questo codice.


---

Se ti piace il progetto metti una stella ⭐ e segnala qualsiasi bug o miglioramento!
