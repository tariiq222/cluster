#!/usr/bin/env python3
"""Generate docs/api/endpoints-table.md — page/endpoint/request/response reference.

Sources:
  - docs/contracts/api/openapi.yaml  (authoritative request/response/status schemas)
  - docs/api/endpoints.md            (runtime middleware, CSRF, controller FQCN per route)
  - apps/web/src/api/generated/cluster.ts (Orval hook names per path+method)
"""

from __future__ import annotations

import pathlib
import re
from collections import OrderedDict

import yaml

REPO_ROOT = pathlib.Path(__file__).resolve().parent.parent
OPENAPI = REPO_ROOT / "docs/contracts/api/openapi.yaml"
ENDPOINTS_MD = REPO_ROOT / "docs/api/endpoints.md"
CLUSTER_TS = REPO_ROOT / "apps/web/src/api/generated/cluster.ts"
OUTPUT = REPO_ROOT / "docs/api/endpoints-table.md"

SPEC = yaml.safe_load(OPENAPI.read_text(encoding="utf-8"))

PAGES: list[tuple[str, str]] = [
    ("/auth/login", "تسجيل الدخول (بيئة التطوير)"),
    ("/identity/login", "تسجيل الدخول"),
    ("/identity/activation", "تفعيل الحساب"),
    ("/identity/me", "الجلسة الحالية (الهوية)"),
    ("/identity/csrf", "تحديث رمز CSRF"),
    ("/identity/logout", "تسجيل الخروج"),
    ("/identity/password", "تغيير كلمة المرور"),
    ("/identity/accounts", "إدارة الحسابات"),
    ("/me/scopes", "تبديل نطاق الصلاحية"),
    ("/me/scope", "اختيار نطاق الصلاحية"),
    ("/me", "الشريط العلوي / الحساب"),
    ("/organization/cluster", "إعداد المنظمة (المجمّع)"),
    ("/organization/facilities", "المنشآت"),
    ("/organization/units", "الوحدات التنظيمية"),
    ("/organization/job-titles", "المسميات الوظيفية"),
    ("/organization/positions", "الوظائف"),
    ("/organization/people", "الأشخاص"),
    ("/organization/assignments", "التكليفات"),
    ("/organization/supervisory-relationships", "العلاقات الإشرافية"),
    ("/organization/temporary-assignments", "التكليفات المؤقتة"),
    ("/organization/import-files", "استيراد الموظفين (رفع الملف)"),
    ("/organization/import-jobs", "استيراد الموظفين (مراجعة)"),
    ("/work-records", "سجلات العمل"),
    ("/work-definition-versions", "إصدارات تعريف العمل"),
    ("/work-definitions", "تعريفات العمل"),
    ("/workflow", "سير العمل"),
    ("/tasks", "المهام"),
    ("/documents/uploads", "رفع المستندات"),
    ("/documents", "المستندات"),
    ("/notifications", "الإشعارات"),
    ("/search", "البحث الشامل"),
    ("/reports", "التقارير والمراقبة"),
    ("/exports", "تنزيل التصديرات"),
    ("/dashboards", "لوحات المعلومات"),
    ("/audit", "سجل التدقيق"),
    ("/platform-settings/current", "إعدادات المنصة الحالية"),
    ("/platform-settings/versions", "إصدارات إعدادات المنصة"),
    ("/platform-settings/calendars", "التقويم الرسمي"),
    ("/platform-operations/maintenance-windows", "نوافذ الصيانة"),
    ("/platform-operations/alert-policies", "سياسات التنبيهات"),
    ("/platform-operations/technical-logs", "السجلات التقنية"),
    ("/platform-operations/overview", "نظرة عامة على المنصة"),
    ("/platform-operations/health", "فحص الحالة (Health)"),
    ("/platform-operations/backups", "النسخ الاحتياطي"),
    ("/platform-operations/restore-requests", "طلبات الاستعادة"),
    ("/authorization", "الصلاحيات والوصول (الإدارة)"),
    ("/internal/documents", "داخلي (عامل المستندات)"),
    ("/up", "نقطة الفحص (Health)"),
]


def page_for(path: str) -> str:
    for prefix, page in PAGES:
        if path.startswith(prefix):
            return page
    return "بدون شاشة (API فقط)"


def module_for(path: str) -> str:
    seg = path.strip("/").split("/", 1)[0]
    names = {
        "auth": "الهوية والمصادقة",
        "identity": "الهوية والمصادقة",
        "me": "الهوية والمصادقة",
        "organization": "المنظمة",
        "documents": "المستندات",
        "internal": "داخلي (المستندات)",
        "tasks": "المهام",
        "work-definitions": "تعريفات العمل",
        "work-definition-versions": "تعريفات العمل",
        "workflow": "سير العمل",
        "work-records": "سجلات العمل",
        "notifications": "الإشعارات",
        "search": "البحث",
        "reports": "التقارير",
        "exports": "التقارير",
        "dashboards": "التقارير",
        "audit": "سجل التدقيق",
        "platform-settings": "إعدادات المنصة",
        "platform-operations": "عمليات المنصة",
        "authorization": "الصلاحيات والوصول",
        "up": "البنية التحتية",
    }
    return names.get(seg, seg)


# ---------------------------------------------------------------- endpoints.md
CARD_RE = re.compile(
    r"### `(GET|POST|PATCH|PUT|DELETE) (/api/v1[^`]*)`\n(.*?)(?=\n### `|\Z)",
    re.MULTILINE | re.DOTALL,
)
CSRF_RE = re.compile(r"\*\*CSRF required:\*\* `(\w+)`")
MIDDLEWARE_RE = re.compile(r"\*\*Middleware chain:\*\* ([^\n]*)")
CONTROLLER_RE = re.compile(r"\*\*Controller FQCN:\*\* `([^`]+)`")
ROUTE_SRC_RE = re.compile(r"\*\*Route source:\*\* `([^`]+)`")


# controllers imported under an alias in web.php (endpoints.md shows the alias)
CONTROLLER_ALIASES = {
    "DocumentLinkController": "Modules\\Documents\\Features\\DocumentLink\\Http\\LinkDocumentController",
}


def parse_endpoints_md() -> dict[tuple[str, str], dict]:
    cards: dict[tuple[str, str], dict] = {}
    text = ENDPOINTS_MD.read_text(encoding="utf-8")
    for method, path, body in CARD_RE.findall(text):
        ctrl = CONTROLLER_RE.search(body).group(1) if CONTROLLER_RE.search(body) else "—"
        cards[(method.upper(), path)] = {
            "csrf": CSRF_RE.search(body).group(1) if CSRF_RE.search(body) else "no",
            "middleware": MIDDLEWARE_RE.search(body).group(1) if MIDDLEWARE_RE.search(body) else "",
            "controller": CONTROLLER_ALIASES.get(ctrl, ctrl),
            "route_source": ROUTE_SRC_RE.search(body).group(1) if ROUTE_SRC_RE.search(body) else "",
        }
    return cards


# ------------------------------------------------------------ Orval hooks
URL_BUILDER_RE = re.compile(r"export const get(\w+)Url = [\s\S]*?=> \{\n([\s\S]*?)\n\}", re.MULTILINE)
HOOK_BLOCK_RE = re.compile(r"export const (\w+) = async \(([\s\S]*?)\n\}")
METHOD_RE = re.compile(r"method: '(\w+)'")


def normalize_template(path: str) -> str:
    path = re.sub(r"\?\$\{stringifiedParams\}", "", path)
    path = re.sub(r"\$\{encodeURIComponent\(String\((\w+)\)\)\}", r"{\1}", path)
    path = re.sub(r"\$\{(\w+)\}", r"{\1}", path)
    return path


def parse_hooks() -> dict[tuple[str, str], str]:
    text = CLUSTER_TS.read_text(encoding="utf-8")
    builders: dict[str, str] = {}
    for name, block in URL_BUILDER_RE.findall(text):
        for path in re.findall(r"`([^`]*)`", block):
            if path.startswith("/api/v1"):
                builders[name] = normalize_template(path)
                break
    methods: dict[str, str] = {}
    for name, body in HOOK_BLOCK_RE.findall(text):
        m = METHOD_RE.search(body)
        if m:
            methods[name] = m.group(1)
    hooks: dict[tuple[str, str], str] = {}
    for builder, path in builders.items():
        hook = re.sub(r"^get|Url$", "", builder)
        # lowerCamel to match export name: getListTasksUrl -> listTasks
        hook = hook[:1].lower() + hook[1:]
        if hook not in methods:
            continue
        method = methods[hook]
        openapi_path = re.sub(r"^/api/v1", "", path)
        hooks[(method.upper(), openapi_path)] = hook
    return hooks


# ------------------------------------------------------------ schemas
def resolve(ref: str):
    node = SPEC
    for part in ref.lstrip("#/").split("/"):
        node = node[part]
    return node


def compact_schema(schema, seen: set[str] | None = None) -> str:
    """One-line schema signature: Name{field*, field?} / Name[] / enum{a,b} / primitives."""
    if not schema:
        return "—"
    if seen is None:
        seen = set()
    if "$ref" in schema:
        ref = schema["$ref"]
        if ref.startswith("#/components/responses/"):
            return ref.rsplit("/", 1)[-1]
        name = ref.rsplit("/", 1)[-1]
        if name in seen:
            return name
        seen = seen | {name}
        target = resolve(ref)
        return compact_schema(target, seen) or name
    if "oneOf" in schema:
        return "(" + " | ".join(compact_schema(s, seen) for s in schema["oneOf"]) + ")"
    if "allOf" in schema:
        parts = [compact_schema(s, seen) for s in schema["allOf"]]
        return " + ".join(p for p in parts if p and p != "—")
    if "anyOf" in schema:
        return "(" + " | ".join(compact_schema(s, seen) for s in schema["anyOf"]) + ")"
    if schema.get("type") == "object":
        props = schema.get("properties", {})
        if not props:
            return schema.get("title", "") or "object"
        required = set(schema.get("required", []))
        inner = ", ".join(
            f"{name}{'*' if name in required else '?'}" for name in props
        )
        title = schema.get("title", "")
        return f"{title}{{{inner}}}" if title else f"{{{inner}}}"
    if schema.get("type") == "array":
        item = compact_schema(schema.get("items", {}), seen)
        return f"{item}[]"
    if "enum" in schema:
        return "enum{" + ",".join(str(v) for v in schema["enum"]) + "}"
    t = schema.get("type", "")
    return t or (schema.get("title", "") or "—")


def op_request(op: dict) -> str:
    parts = []
    query = []
    for p in op.get("parameters", []):
        if "$ref" in p:
            p = resolve(p["$ref"])
        if p.get("in") == "query":
            query.append(p.get("name", "?"))
    if query:
        parts.append("?" + ", ".join(query))
    body = op.get("requestBody")
    if body:
        content = body.get("content") or {}
        for ct, c in content.items():
            if "json" not in ct:
                continue
            sch = c.get("schema")
            if sch:
                req = compact_schema(sch)
                if req != "—":
                    parts.append(req)
            break
        parts.append("*" if body.get("required") else "")
    return " ".join(parts) if parts else "—"


def op_response(op: dict) -> str:
    outs = []
    for status in ("200", "201", "202", "204", "206"):
        resp = op.get("responses", {}).get(status)
        if not resp:
            continue
        if "$ref" in resp:
            name = resp["$ref"].rsplit("/", 1)[-1]
            outs.append(f"{status}: {name}")
            continue
        content = (resp.get("content") or {})
        if not content:
            outs.append(f"{status}: —")
            continue
        ct, c = next(iter(content.items()))
        sch = c.get("schema")
        if not sch:
            outs.append(f"{status}: —")
            continue
        if ct != "application/json":
            outs.append(f"{status}: {ct}")
            continue
        outs.append(f"{status}: {compact_schema(sch)}")
    if not outs:
        for status, resp in op.get("responses", {}).items():
            if status.startswith("2"):
                outs.append(f"{status}: —")
    return " · ".join(outs) if outs else "—"


def status_codes(op: dict) -> str:
    return ", ".join(op.get("responses", {}).keys())


# ------------------------------------------------------------ rows
def main() -> None:
    cards = parse_endpoints_md()
    hooks = parse_hooks()
    rows: list[dict] = []
    planned: list[dict] = []
    for path, item in SPEC["paths"].items():
        for method in ("get", "post", "put", "patch", "delete"):
            op = item.get(method)
            if not op:
                continue
            m = method.upper()
            card = cards.get((m, f"/api/v1{path}"))
            hook = hooks.get((m, path))
            if card:
                auth = "جلسة + CSRF" if card["csrf"] == "yes" else "جلسة"
                controller = card["controller"]
                if "identity_csrf" in card["middleware"]:
                    auth = "جلسة + CSRF"
            else:
                auth = "—"
                controller = "—"
            sec = op.get("security")
            if sec is not None and sec == []:
                auth = "عام"
            elif sec and "documentsWorkerToken" in sec[0]:
                auth = "داخلي (Worker Token)"
            if "/internal/" in path:
                auth = "داخلي (Worker Token)"
            row = {
                "page": page_for(path),
                "module": module_for(path),
                "method": m,
                "endpoint": f"/api/v1{path}",
                "request": op_request(op),
                "response": op_response(op),
                "status": status_codes(op),
                "auth": auth,
                "controller": controller,
                "hook": hook or "—",
            }
            if card:
                rows.append(row)
            else:
                row["op"] = op.get("summary", "").strip()
                planned.append(row)

    rows.sort(key=lambda r: (r["module"], r["endpoint"], r["method"]))
    modules: OrderedDict[str, list[dict]] = OrderedDict()
    for r in rows:
        modules.setdefault(r["module"], []).append(r)

    lines: list[str] = []
    lines.append("# جدول الشاشات والـ Endpoints")
    lines.append("")
    lines.append(f"> ملف مولّد آلياً من عقد `docs/contracts/api/openapi.yaml` وملف `docs/api/endpoints.md`"
                 f" وهوكات Orval في `apps/web/src/api/generated/cluster.ts`. لا تُعدَّل يدوياً.")
    lines.append(f"> لإعادة التوليد: `python3 scripts/generate-endpoint-table.py`")
    lines.append("")
    lines.append(f"- العمليات الحيّة (مسجّلة في `web.php`): **{len(rows)}**")
    lines.append(f"- العمليات المخططة (موثّقة في العقد فقط): **{len(planned)}**")
    lines.append(f"- الوحدات الحيّة: **{len(modules)}**")
    lines.append(f"- الصفحات/الشاشات المربوطة: **{len(set(r['page'] for r in rows if 'بدون شاشة' not in r['page']))}**")
    lines.append(f"- هوكات Orval موجودة: **{sum(1 for r in rows if r['hook'] != '—')}**")
    lines.append("")
    lines.append("أعمدة `الـ Request` و`الـ Response` بتنسيق مختصر: `Schema{الحقل*, الحقل?}` حيث `*` إلزامي و`?` اختياري؛ "
                 "`enum{a,b}` قيم ثابتة؛ `X[]` مصفوفة؛ `?a, b` باراميترات استعلام.")
    lines.append("")

    for module, mod_rows in modules.items():
        lines.append(f"## {module}")
        lines.append("")
        lines.append("| # | الصفحة | Method | الـ Endpoint | الـ Request | الـ Response | Status Codes | Auth | Controller | Orval Hook |")
        lines.append("|---|--------|--------|-------------|-------------|-------------|--------------|------|------------|-------------|")
        for i, r in enumerate(mod_rows, 1):
            req = r["request"].replace("|", "\\|").replace("\n", " ")
            resp = r["response"].replace("|", "\\|")
            lines.append(
                f"| {i} | {r['page']} | `{r['method']}` | `{r['endpoint']}` | `{req}` | `{resp}` | {r['status']} | {r['auth']} | `{r['controller']}` | `{r['hook']}` |"
            )
        lines.append("")

    if planned:
        lines.append("## ملحق: مسارات مخططة (عقد فقط — بلا تنفيذ)")
        lines.append("")
        lines.append("هذه المسارات موثّقة في `openapi.yaml` لكنها غير مسجّلة في `apps/api/routes/web.php` ولا تملك وحدة تحكم أو شاشة.")
        lines.append("")
        lines.append("| Method | الـ Endpoint | الـ Request | Status Codes |")
        lines.append("|--------|-------------|-------------|--------------|")
        for r in planned:
            req = r["request"].replace("|", "\\|").replace("\n", " ")
            lines.append(f"| `{r['method']}` | `{r['endpoint']}` | `{req}` | {r['status']} |")
        lines.append("")

    OUTPUT.write_text("\n".join(lines), encoding="utf-8")
    print(f"written {len(rows)} live + {len(planned)} planned rows -> {OUTPUT}")


if __name__ == "__main__":
    main()
