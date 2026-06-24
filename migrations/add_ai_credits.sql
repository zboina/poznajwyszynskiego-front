-- Faza 3: pula zapytań AI doładowywana darowizną (model pay-what-you-want → kredyty).
-- Uruchom: psql -U wyszynski -d wyszynski_dzielazebrane -f migrations/add_ai_credits.sql
ALTER TABLE "user" ADD COLUMN IF NOT EXISTS ai_credits integer NOT NULL DEFAULT 0;
