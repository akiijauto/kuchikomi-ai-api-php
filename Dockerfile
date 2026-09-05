# syntax=docker/dockerfile:1

# PHP実装の実行イメージ。
# Go版(distroless)/Rails版(ruby:slim)と並べて、同じ機能が実行時に何を必要とするかを
# 比べられるようにしてある。テーブル定義の正本はここには置かず、元リポジトリ
# (kuchikomi-ai-multi-stack)のままにしてある。このイメージ自体はDBスキーマの投入をしない。

# ---- 依存関係のインストール ----
# composer公式イメージ。依存だけを先にインストールして、アプリ本体(src/public)の
# 変更ではこの層が再利用されるようにする(composer.json/composer.lockが変わらない限り)。
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --optimize-autoloader \
        --no-interaction \
        --no-scripts \
        --no-progress

# ---- 実行イメージ ----
FROM php:8.4-cli-bookworm AS runtime

# pdo_pgsql: PostgreSQL(RDS/Supabase互換スキーマ)への接続に使う。
# composer.jsonのext-pdoはPDO本体の要求で、実ドライバ(pdo_pgsql)は別途ビルドしないと
# 入らないため、ここで明示的にインストールする。あわせてHEALTHCHECK用のcurlも入れる。
RUN apt-get update && apt-get install -y --no-install-recommends \
        libpq-dev \
        curl \
    && docker-php-ext-install -j"$(nproc)" pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY composer.json composer.lock ./
COPY public ./public
COPY src ./src

# 非rootユーザーで動かす。php公式イメージには www-data (uid=33) が最初から
# 存在するのでそれを使う(Rails版が独自にrailsユーザーを作っているのとは対照的)。
RUN chown -R www-data:www-data /app
USER www-data

ENV PORT=3000
EXPOSE ${PORT}

# 本番ではこのビルトインサーバーの前段にnginx/php-fpmを置く前提。
# `php -S` は開発・デモ用途のシングルスレッド実装で、並行リクエストを直列にしか
# 捌けない・静的ファイル配信の最適化やTLS終端も無い。Go版・Rails版のように
# 「そのまま本番に出せる」実行系ではないので、嘘の完成度を出さないためここに明記する。
#
# HEALTHCHECKの方式について:
# Go版はシェルの無いdistrolessイメージだったため、バイナリ自身に `-healthcheck` フラグを
# 持たせて自己診断させていた(execフォームでシェルを介さず起動する構成のため)。
# このイメージ(php:8.4-cli)にはシェルとcurlがあるので、素直にHTTP越しに叩く
# シェルフォームのCMDで十分(実行時に$PORTを展開できる利点もある)。
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -fsS "http://localhost:${PORT}/api/health" || exit 1

CMD ["sh", "-c", "php -S 0.0.0.0:${PORT} -t public"]
