#!/usr/bin/env python3
"""CLI for the standalone Facebook disaster crawler."""

from __future__ import annotations

import argparse
import os
import sys
import uuid
from datetime import datetime, timezone
from pathlib import Path

from crawler.browser.proxy import resolve_proxy
from crawler.discovery.facebook import Target, load_targets, target_from_user_input
from crawler.keywords import filter_records, load_keywords
from crawler.paths import PROJECT_ROOT, project_path
from crawler.retry.policy import (
    EXIT_ERROR,
    EXIT_OK,
    EXIT_SESSION_FAILURE,
    EXIT_ZERO_RECORDS,
    RetryConfig,
)
from crawler.scraper import ScrapeOptions, scrape_target
from crawler.storage.jsonl import write_jsonl, write_meta
from crawler.storage.sqlite_store import PostStore


def load_dotenv() -> None:
    try:
        from dotenv import load_dotenv as _load
    except ImportError:
        return
    _load(PROJECT_ROOT / ".env")


def env_bool(name: str, default: bool = False) -> bool:
    raw = os.getenv(name)
    if raw is None:
        return default
    return raw.strip().lower() in ("1", "true", "yes", "on")


def build_parser() -> argparse.ArgumentParser:
    p = argparse.ArgumentParser(
        description=(
            "Standalone Playwright crawler for authorized Facebook public pages "
            "and disaster hashtags (#防災 #淹水). Does not bypass login/CAPTCHA."
        )
    )
    p.add_argument(
        "--cookie-path",
        default=os.getenv("CRAWLER_COOKIE_PATH", "data/browser_state/cookie_state.json"),
    )
    sub = p.add_subparsers(dest="command", required=True)

    login = sub.add_parser("login", help="Open browser, log in manually, save storage state.")
    login.add_argument("--login-url", default="https://www.facebook.com/")
    login.add_argument("--wait-seconds", type=int, default=0)
    login.add_argument("--headless", action="store_true")
    login.add_argument("--stealth", action="store_true")
    login.add_argument("--fingerprint", default=None, help="Profile name, e.g. tw_desktop_chrome")
    login.add_argument("--use-proxy", action="store_true", help="Explicitly enable proxy.")
    login.add_argument("--proxy-server", default=None)
    login.add_argument("--proxy-username", default=None)
    login.add_argument("--proxy-password", default=None)

    scrape = sub.add_parser("scrape", help="Scrape one page, group, or hashtag target.")
    scrape.add_argument(
        "--kind",
        choices=("auto", "page", "group", "hashtag"),
        default="auto",
        help="auto: detect from URL (groups/ → group, #tag → hashtag).",
    )
    scrape.add_argument(
        "--target",
        required=True,
        help="Page/group URL, or hashtag (e.g. 防災 / #淹水).",
    )
    scrape.add_argument("--scrolls", type=int, default=3)
    scrape.add_argument("--limit", type=int, default=50)
    scrape.add_argument("--output", default="data/output/posts.jsonl")
    scrape.add_argument("--meta-output", default=None)
    scrape.add_argument("--db", default="data/store/posts.db")
    scrape.add_argument("--no-db", action="store_true")
    scrape.add_argument("--keywords", default=None, help="Filter by keywords file.")
    scrape.add_argument("--headless", action="store_true")
    scrape.add_argument("--stealth", action="store_true")
    scrape.add_argument("--human", action="store_true")
    scrape.add_argument("--fingerprint", default=None)
    scrape.add_argument("--use-proxy", action="store_true")
    scrape.add_argument("--proxy-server", default=None)
    scrape.add_argument("--proxy-username", default=None)
    scrape.add_argument("--proxy-password", default=None)
    scrape.add_argument("--scroll-delay-ms", type=int, default=1500)
    scrape.add_argument("--retry-max-attempts", type=int, default=1)
    scrape.add_argument("--allow-zero-records", action="store_true")
    scrape.add_argument("--allow-no-cookie", action="store_true")
    scrape.add_argument("--skip-health-check", action="store_true")
    scrape.add_argument("--hashtag-style", choices=("hashtag", "search_posts"), default="hashtag")
    scrape.add_argument("--min-chars", type=int, default=20)

    batch = sub.add_parser("batch", help="Scrape all targets from a targets file.")
    batch.add_argument(
        "--targets",
        default="configs/targets.example.txt",
        help="Targets file path.",
    )
    batch.add_argument("--output-dir", default="data/output/batch")
    batch.add_argument("--db", default="data/store/posts.db")
    batch.add_argument("--no-db", action="store_true")
    batch.add_argument("--keywords", default="configs/keywords.txt")
    batch.add_argument("--filter-keywords", action="store_true", help="Keep only keyword hits.")
    batch.add_argument("--headless", action="store_true")
    batch.add_argument("--stealth", action="store_true")
    batch.add_argument("--human", action="store_true")
    batch.add_argument("--fingerprint", default=None)
    batch.add_argument("--use-proxy", action="store_true")
    batch.add_argument("--proxy-server", default=None)
    batch.add_argument("--proxy-username", default=None)
    batch.add_argument("--proxy-password", default=None)
    batch.add_argument("--scroll-delay-ms", type=int, default=1500)
    batch.add_argument("--retry-max-attempts", type=int, default=1)
    batch.add_argument("--allow-no-cookie", action="store_true")
    batch.add_argument("--skip-health-check", action="store_true")
    batch.add_argument("--disaster-mode", action="store_true",
                       help="Enable stealth+human+fingerprint+retry=3 (proxy still needs --use-proxy).")
    batch.add_argument("--hashtag-style", choices=("hashtag", "search_posts"), default="hashtag")
    batch.add_argument("--min-chars", type=int, default=20)

    return p


def _has_fb_login_cookie(context) -> bool:
    try:
        cookies = context.cookies()
    except Exception:
        return False
    names = {c.get("name") for c in cookies}
    # c_user = logged-in Facebook user id
    return "c_user" in names


def cmd_login(args: argparse.Namespace) -> int:
    import time

    from playwright.sync_api import sync_playwright

    from crawler.browser.factory import create_browser_bundle
    from crawler.browser.fingerprint import get_profile

    cookie_path = project_path(args.cookie_path)
    cookie_path.parent.mkdir(parents=True, exist_ok=True)

    try:
        proxy = resolve_proxy(
            use_proxy=args.use_proxy,
            server=args.proxy_server,
            username=args.proxy_username,
            password=args.proxy_password,
        )
    except ValueError as exc:
        print(exc, file=sys.stderr)
        return EXIT_ERROR

    fp = get_profile(args.fingerprint or ("tw_desktop_chrome" if args.stealth else None))
    apply_fp = bool(args.stealth or args.fingerprint)
    # Default 5 minutes if not specified as 0 (interactive Enter)
    wait_seconds = args.wait_seconds if args.wait_seconds > 0 else 300

    print("=" * 50)
    print("即將開啟 Chromium → Facebook 登入頁")
    print("請在視窗內完成登入（含 2FA）。")
    print("偵測到已登入（c_user cookie）會自動存檔；")
    print(f"最多等待 {wait_seconds} 秒。請勿手動關掉瀏覽器視窗。")
    print(f"存檔位置：{cookie_path}")
    print("=" * 50)

    saved = False
    with sync_playwright() as p:
        bundle = create_browser_bundle(
            p,
            headless=args.headless,
            storage_state=cookie_path if cookie_path.exists() else None,
            fingerprint=fp,
            apply_fingerprint=apply_fp,
            stealth=args.stealth,
            proxy=proxy,
        )
        try:
            try:
                bundle.page.goto(args.login_url, wait_until="domcontentloaded", timeout=60_000)
            except Exception as exc:
                print(f"開啟登入頁失敗：{exc}", file=sys.stderr)
                return EXIT_ERROR

            deadline = time.time() + wait_seconds
            last_print = 0.0
            while time.time() < deadline:
                try:
                    if _has_fb_login_cookie(bundle.context):
                        # 給頁面一點時間寫入其餘 cookie
                        try:
                            bundle.page.wait_for_timeout(2000)
                        except Exception:
                            pass
                        bundle.context.storage_state(path=str(cookie_path))
                        saved = True
                        print(f"✓ 偵測到登入，已儲存 storage state：{cookie_path}")
                        break
                    now = time.time()
                    if now - last_print >= 15:
                        remain = int(deadline - now)
                        print(f"…等待登入中（剩餘約 {remain} 秒）")
                        last_print = now
                    bundle.page.wait_for_timeout(1500)
                except Exception as exc:
                    # 視窗被關
                    print(f"瀏覽器已關閉或中斷：{exc}", file=sys.stderr)
                    break

            if not saved:
                # 最後再試存一次（可能已登入但 cookie 名稱不同 / 或仍未登入）
                try:
                    bundle.context.storage_state(path=str(cookie_path))
                    if _has_fb_login_cookie(bundle.context):
                        saved = True
                        print(f"✓ 已儲存 storage state：{cookie_path}")
                    else:
                        print(
                            "⚠ 已寫入 storage state，但尚未偵測到 c_user（可能未登入成功）。",
                            file=sys.stderr,
                        )
                        print(f"   檔案：{cookie_path}", file=sys.stderr)
                except Exception as exc:
                    print(f"儲存失敗：{exc}", file=sys.stderr)
                    return EXIT_ERROR
        finally:
            try:
                bundle.close()
            except Exception:
                pass

    return EXIT_OK if saved else EXIT_ERROR


def _build_opts(args: argparse.Namespace, *, allow_zero: bool) -> ScrapeOptions | None:
    try:
        proxy = resolve_proxy(
            use_proxy=getattr(args, "use_proxy", False),
            server=getattr(args, "proxy_server", None),
            username=getattr(args, "proxy_username", None),
            password=getattr(args, "proxy_password", None),
        )
    except ValueError as exc:
        print(exc, file=sys.stderr)
        return None

    stealth = bool(getattr(args, "stealth", False))
    human = bool(getattr(args, "human", False))
    fingerprint = getattr(args, "fingerprint", None)
    retry_max = int(getattr(args, "retry_max_attempts", 1))

    if getattr(args, "disaster_mode", False):
        stealth = True
        human = True
        fingerprint = fingerprint or "tw_desktop_chrome"
        retry_max = max(retry_max, 3)

    return ScrapeOptions(
        headless=bool(getattr(args, "headless", False) or env_bool("CRAWLER_HEADLESS")),
        stealth=stealth,
        human=human,
        fingerprint_name=fingerprint,
        storage_state=project_path(args.cookie_path),
        proxy=proxy,
        scroll_delay_ms=int(getattr(args, "scroll_delay_ms", 1500)),
        min_chars=int(getattr(args, "min_chars", 20)),
        hashtag_style=getattr(args, "hashtag_style", "hashtag"),
        skip_health_check=bool(getattr(args, "skip_health_check", False)),
        allow_no_cookie=bool(getattr(args, "allow_no_cookie", False)),
        retry=RetryConfig(max_attempts=max(1, retry_max)),
    )


def cmd_scrape(args: argparse.Namespace) -> int:
    try:
        target = target_from_user_input(
            args.target,
            kind=None if args.kind == "auto" else args.kind,
            scrolls=args.scrolls,
            limit=args.limit,
            allow_zero=True if args.allow_zero_records else None,
        )
    except ValueError as exc:
        print(exc, file=sys.stderr)
        return EXIT_ERROR

    if args.allow_zero_records:
        target.allow_zero = True

    opts = _build_opts(args, allow_zero=target.allow_zero)
    if opts is None:
        return EXIT_ERROR

    print(f"Target kind={target.kind} url/query={target.discovery_query}")


    result = scrape_target(target, opts)
    records = list(result.records or [])

    if args.keywords:
        kws = load_keywords(project_path(args.keywords))
        records = filter_records(records, kws)

    out = project_path(args.output)
    write_jsonl(out, records)
    print(f"Wrote {len(records)} records → {out}")

    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid.uuid4().hex[:6]
    if not args.no_db and records:
        with PostStore(project_path(args.db)) as store:
            new_c, dup_c = store.upsert_records(records, run_id=run_id)
            print(f"DB upsert: new={new_c} dup={dup_c} total={store.count()} → {args.db}")

    meta = dict(result.meta or {})
    meta["exit_code"] = result.exit_code
    meta["error_class"] = result.error_class.value
    meta["message"] = result.message
    meta["output"] = str(out)
    meta["run_id"] = run_id
    write_meta(project_path(args.meta_output) if args.meta_output else None, meta)
    if result.message and result.exit_code != EXIT_OK:
        print(result.message, file=sys.stderr)
    return result.exit_code


def cmd_batch(args: argparse.Namespace) -> int:
    opts = _build_opts(args, allow_zero=True)
    if opts is None:
        return EXIT_ERROR
    # batch always allows no-cookie for local fixtures when flagged
    opts.allow_no_cookie = opts.allow_no_cookie or args.allow_no_cookie

    targets_path = project_path(args.targets)
    if not targets_path.exists():
        print(f"Targets file not found: {targets_path}", file=sys.stderr)
        return EXIT_ERROR

    targets = load_targets(str(targets_path))
    if not targets:
        print("No targets to scrape.", file=sys.stderr)
        return EXIT_ERROR

    keywords = load_keywords(project_path(args.keywords)) if args.keywords else []
    out_dir = project_path(args.output_dir)
    out_dir.mkdir(parents=True, exist_ok=True)
    run_id = datetime.now(timezone.utc).strftime("%Y%m%dT%H%M%SZ") + "-" + uuid.uuid4().hex[:6]

    worst_exit = EXIT_OK
    summary: list[dict] = []

    for t in targets:
        print(f"\n=== {t.kind}: {t.discovery_query} ===")
        result = scrape_target(t, opts)
        records = list(result.records or [])
        if args.filter_keywords and keywords:
            records = filter_records(records, keywords)
        elif keywords:
            # annotate only
            annotated = []
            for rec in records:
                row = dict(rec)
                from crawler.extract.posts import match_keywords

                hits = match_keywords(str(rec.get("content") or ""), keywords)
                if hits:
                    row["matched_keywords"] = hits
                annotated.append(row)
            records = annotated

        out_path = out_dir / f"{t.slug()}.jsonl"
        write_jsonl(out_path, records)
        meta_path = out_dir / f"{t.slug()}.meta.json"
        meta = dict(result.meta or {})
        meta["exit_code"] = result.exit_code
        meta["error_class"] = result.error_class.value
        meta["message"] = result.message
        meta["output"] = str(out_path)
        meta["run_id"] = run_id
        write_meta(meta_path, meta)

        if not args.no_db and records:
            with PostStore(project_path(args.db)) as store:
                new_c, dup_c = store.upsert_records(records, run_id=run_id)
                print(f"  records={len(records)} new={new_c} dup={dup_c} → {out_path}")
        else:
            print(f"  records={len(records)} exit={result.exit_code} → {out_path}")

        summary.append(
            {
                "target": t.discovery_query,
                "kind": t.kind,
                "exit_code": result.exit_code,
                "error_class": result.error_class.value,
                "record_count": len(records),
            }
        )
        # session failure is worst; don't let zero-records (3) override ok for hashtags
        if result.exit_code == EXIT_SESSION_FAILURE:
            worst_exit = EXIT_SESSION_FAILURE
        elif result.exit_code == EXIT_ERROR and worst_exit not in (
            EXIT_SESSION_FAILURE,
        ):
            worst_exit = EXIT_ERROR
        elif result.exit_code == EXIT_ZERO_RECORDS and worst_exit == EXIT_OK:
            worst_exit = EXIT_ZERO_RECORDS

    write_meta(out_dir / f"run_summary_{run_id}.json", {
        "run_id": run_id,
        "targets": summary,
        "worst_exit": worst_exit,
    })
    print(f"\nBatch done. run_id={run_id} worst_exit={worst_exit}")
    return worst_exit


def main(argv: list[str] | None = None) -> int:
    load_dotenv()
    # Ensure project root on sys.path when run as script
    if str(PROJECT_ROOT) not in sys.path:
        sys.path.insert(0, str(PROJECT_ROOT))

    parser = build_parser()
    args = parser.parse_args(argv)

    if args.command == "login":
        return cmd_login(args)
    if args.command == "scrape":
        return cmd_scrape(args)
    if args.command == "batch":
        return cmd_batch(args)
    parser.error(f"Unknown command: {args.command}")
    return EXIT_ERROR


if __name__ == "__main__":
    raise SystemExit(main())
