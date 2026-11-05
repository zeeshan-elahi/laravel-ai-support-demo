# Laravel RAG Demo

Ask questions about your own documents and get answers that cite the exact passages they came from.

Upload a PDF or text file, ask a question in plain language, and the app finds the most relevant
parts of your documents, sends them to Claude, and shows the answer with the quoted sources and file
names underneath. No answer is given without a source to back it.

> **Status:** working demo. Upload, retrieval, cited answers and a first evaluation are done; a
> harder evaluation and a short video are next (see the roadmap below).

## How it works

1. **Upload.** A PDF or TXT file is stored and its text is extracted.
2. **Chunk.** The text is split into overlapping pieces of about 800 tokens, so each piece keeps
   enough context to make sense on its own.
3. **Embed.** Each piece is turned into an embedding (a list of numbers that captures its meaning)
   with Voyage AI and saved in Postgres using pgvector.
4. **Retrieve.** A question is embedded the same way, and the closest pieces are found by cosine
   similarity, using an HNSW index so the search stays fast as documents grow.
5. **Answer.** The top pieces are sent to Claude as documents with citations turned on. Claude
   answers from those pieces only, and every claim in the answer points back to the passage it
   came from.

## Stack

| Part | Choice |
|---|---|
| App | Laravel 13, PHP 8.4 |
| Database | PostgreSQL 17 with pgvector (Laravel's built-in vector column and index) |
| Embeddings | Voyage AI (`voyage-4`, 1024 dimensions) |
| Answers | Claude through the official Anthropic PHP SDK, with citations |
| Runtime | Docker Compose: one app container, one database container |

The models, chunk size and number of retrieved pieces are set in `config/rag.php` and can be changed
from `.env`.

## Running it locally

Requirements: Docker with Compose. PHP and Composer run inside the containers, so nothing else is
needed on the host.

```bash
cp .env.example .env
# set UID/GID to your own user (id -u / id -g)
# add ANTHROPIC_API_KEY and VOYAGE_API_KEY

docker compose up -d --build
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

From the command line:

```bash
docker compose exec app php artisan rag:ingest storage/app/some-file.pdf
docker compose exec app php artisan rag:ask "What does the document say about X?"
```

The app runs at http://localhost:8010 and Postgres listens on `127.0.0.1:5433`.

## Evaluation

The evaluation set is in `database/eval/questions.json`. It runs against eight public
[PromoStandards](https://promostandards.org) web-service specifications (Inventory 2.0, Invoice,
Order Shipment Notification, Pricing and Configuration, Product Compliance, Product Data 2.0,
Product Media, Purchase Order): 128 chunks in total. The spec documents themselves are not in this
repository; download them from promostandards.org and ingest them with `rag:ingest`.

Each of the 20 answerable questions names the document and pages that hold the answer and the
keywords a correct answer must contain. Two more questions are deliberately outside the documents.

| Measure | What it checks | Result |
|---|---|---|
| Retrieval hit rate | An expected page is among the 5 retrieved chunks | 20/20 |
| Answer correctness | The answer cites a source, cites the right document and contains every expected keyword | 20/20 |
| Out-of-scope handling | The app says the documents don't cover it instead of answering from general knowledge | 2/2 |

Run on 5 November 2026 with `voyage-4` embeddings and `claude-opus-5-5`: about 83K input and 8.6K
output tokens, roughly $0.50.

**Harder set** (`database/eval/questions-hard.json`): 12 questions in plain words without the specs'
field names ("made to order rather than kept on the shelf", "invoices a supplier has cancelled"),
2 questions that need two specs together (where a purchase order's charge and decoration ids come
from), 2 questions about rules shared by several specs, and 2 more out-of-scope questions.

| Measure | Result |
|---|---|
| Retrieval hit rate | 15/16 |
| Answer correctness | 16/16 |
| Out-of-scope handling | 2/2 (see note) |

About 72K input and 12.5K output tokens, roughly $0.55.

Notes on the harder set:

- **Retrieval miss (question 114).** The question needs the Purchase Order page that says a
  decoration id comes from the Pricing and Configuration service. That page ranked just outside the
  top 5; a neighbouring Purchase Order page was retrieved instead. The answer was still correct and
  cited both specs, but the passage that states the link was not among its sources. Hybrid search
  (keyword plus vector) is the planned fix.
- **Grader fix (question 122).** The first run marked an out-of-scope answer as wrong. The model had
  answered "The documents don't give a minimum order quantity…" and then listed the fields where a
  supplier would report one, which is the right behaviour. The grader's list of "not covered"
  phrasings missed "don't give"; it was widened and question 122 re-run alone, which passed.
- **Grading is keyword-based.** It checks that the expected terms appear and that the right
  documents are cited. It is transparent and cheap, but short keywords can match inside other words,
  so read the saved answers before trusting a score.

```bash
docker compose exec app php artisan rag:eval                        # whole set, stops at an estimated $1.00
docker compose exec app php artisan rag:eval --file=database/eval/questions-hard.json
docker compose exec app php artisan rag:eval --only=3,7 --budget=0.25
docker compose exec app php artisan rag:eval --pace=20              # free Voyage accounts: stay under 3 requests a minute
```

`--budget` is checked before each question, so a run can go over it by at most one question
(about $0.03). Detailed results for each run are saved under `storage/app/private/eval/`.

## Roadmap

- [x] Project setup: Docker, Postgres with pgvector, documents and chunks tables
- [x] Ingest: upload, text extraction, chunking, embeddings
- [x] Ask: retrieval and cited answers on a simple page
- [x] Evaluation: 20 questions, results in this README
- [x] Harder evaluation: paraphrased and cross-document questions
- [ ] Hybrid search (Postgres full-text plus vector) and queued ingest
- [ ] Short video walkthrough

## Scope

This is a focused demo of retrieval-augmented generation, kept small on purpose. It has no login,
no multiple users and no chat history. The same pieces (chunking, embeddings, vector search and
cited answers) carry over to a document assistant inside an existing product.

## License

MIT. See [LICENSE](LICENSE). The PromoStandards specifications used for the evaluation belong to
PromoStandards and are not included.

## Author

Zeeshan Elahi, senior full-stack engineer in Dubai. PHP/Laravel, React, AI features, payments and
integrations. [zeeshanelahi.com](https://www.zeeshanelahi.com)
