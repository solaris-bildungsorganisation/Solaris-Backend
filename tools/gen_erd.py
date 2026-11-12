"""Generate ERD (Graphviz) + DBML + data dictionary JSON from the live PostgreSQL schema.
Usage: python3 gen_erd.py <out_dir>
"""
import json, os, subprocess, sys, html

OUT = sys.argv[1]
os.makedirs(OUT, exist_ok=True)
ENV = dict(os.environ, MYSQL_PWD="secret")
DB = "solaris"


def q(sql):
    r = subprocess.run(["mysql", "-usolaris", "-N", "-B", "--raw", DB, "-e", sql],
                       capture_output=True, text=True, env=ENV, check=True)
    return json.loads(r.stdout.strip() or "null") or []


S = f"TABLE_SCHEMA='{DB}'"
cols = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('table_name',TABLE_NAME,'column_name',COLUMN_NAME,'ordinal_position',ORDINAL_POSITION,
  'type', IF(COLUMN_TYPE='tinyint(1)','boolean',COLUMN_TYPE), 'nullable', IS_NULLABLE='YES', 'dflt', COLUMN_DEFAULT,
  'comment', NULLIF(COLUMN_COMMENT,''), 'generated', NULLIF(GENERATION_EXPRESSION,'')))
  FROM information_schema.COLUMNS WHERE {S}""")
cols.sort(key=lambda c: (c["table_name"], c["ordinal_position"]))
for c in cols:
    c["nullable"] = bool(c["nullable"])
    if c.get("generated"):
        c["type"] += " (virtual)"
pks = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('table_name',TABLE_NAME,'column_name',COLUMN_NAME))
  FROM information_schema.KEY_COLUMN_USAGE WHERE {S} AND CONSTRAINT_NAME='PRIMARY'""")
fks = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('table_name',k.TABLE_NAME,'column_name',k.COLUMN_NAME,'ref_table',k.REFERENCED_TABLE_NAME,
  'ref_column',k.REFERENCED_COLUMN_NAME,'on_delete',
  CASE r.DELETE_RULE WHEN 'CASCADE' THEN 'c' WHEN 'SET NULL' THEN 'n' WHEN 'RESTRICT' THEN 'r' ELSE 'a' END))
  FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r
  ON r.CONSTRAINT_SCHEMA=k.TABLE_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME
  WHERE k.{S} AND k.REFERENCED_TABLE_NAME IS NOT NULL""")
uniq = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('table_name',TABLE_NAME,'indexname',INDEX_NAME,'columns',cols)) FROM
  (SELECT TABLE_NAME, INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) cols FROM information_schema.STATISTICS
   WHERE {S} AND NON_UNIQUE=0 AND INDEX_NAME<>'PRIMARY' GROUP BY TABLE_NAME, INDEX_NAME) x""")
checks = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('table_name',t.TABLE_NAME,'conname',c.CONSTRAINT_NAME,'def',c.CHECK_CLAUSE))
  FROM information_schema.CHECK_CONSTRAINTS c JOIN information_schema.TABLE_CONSTRAINTS t
  ON t.CONSTRAINT_SCHEMA=c.CONSTRAINT_SCHEMA AND t.CONSTRAINT_NAME=c.CONSTRAINT_NAME WHERE c.CONSTRAINT_SCHEMA='{DB}'""")
tcomments = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('table_name',TABLE_NAME,'comment',TABLE_COMMENT))
  FROM information_schema.TABLES WHERE {S} AND TABLE_TYPE='BASE TABLE'""")
triggers = q(f"""SELECT JSON_ARRAYAGG(JSON_OBJECT('name',TRIGGER_NAME,'table',EVENT_OBJECT_TABLE,'event',EVENT_MANIPULATION))
  FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='{DB}'""")

DOMAINS = {
    "Identity · الهوية": (["users", "password_histories", "account_status_histories"], "#FDECEA"),
    "RBAC · الأدوار والسياقات (spatie)": (["contexts", "roles", "permissions", "role_has_permissions", "model_has_roles", "model_has_permissions", "access_scopes", "user_contexts", "account_type_requests"], "#FFF4DE"),
    "Sessions & Security · الجلسات والأمان": (["personal_access_tokens", "user_sessions", "verification_codes", "auth_events"], "#EDE7F6"),
    "Geo & Region policy · المناطق": (["countries", "regions", "region_policies", "user_region_changes"], "#E3F2FD"),
    "I18N · اللغات": (["languages", "user_languages", "language_requirements", "ui_translations"], "#E0F2F1"),
    "ORG · المؤسسات": (["organizations", "organization_memberships", "organization_invitations"], "#E8F5E9"),
    "COM · الإشعارات": (["notification_channels", "notification_types", "notification_templates", "notification_preferences", "notifications", "notification_deliveries"], "#FCE4EC"),
    "ADMIN & Audit · الإدارة والتدقيق": (["activity_log", "metric_snapshots", "report_exports", "success_stories"], "#F3F4F6"),
}
INFRA = {"job_batches", "failed_jobs"}
WHO = {"created_by", "updated_by", "granted_by", "revoked_by", "reviewed_by", "verified_by", "changed_by", "added_by",
       "invited_by", "accepted_by", "consent_recorded_by", "requested_by"}

by_table = {}
for c in cols:
    by_table.setdefault(c["table_name"], []).append(c)
pk = {(p["table_name"], p["column_name"]) for p in pks}
fk = {(f["table_name"], f["column_name"]): f for f in fks}
tcom = {t["table_name"]: t["comment"] for t in tcomments}

# ---------------- Graphviz ERD ----------------
def node(t, compact=False, stub=False):
    head = "#6B7280" if stub else "#1F2937"
    rows = [f'<tr><td colspan="2" bgcolor="{head}"><font color="white"><b>{t}</b></font></td></tr>']
    if not stub:
        for c in by_table[t]:
            name = c["column_name"]
            is_pk, is_fk = (t, name) in pk, (t, name) in fk
            if compact and not (is_pk or (is_fk and name not in WHO)):
                continue
            mark = "PK " if is_pk else ("FK " if is_fk else "")
            o, cl = ("<b>", "</b>") if is_pk else (("<i>", "</i>") if is_fk else ("", ""))
            if is_fk and name in WHO:
                o, cl = '<font color="#9CA3AF">', "</font>"
            null = "" if c["nullable"] else " *"
            rows.append(f'<tr><td align="left" port="{name}">{o}{mark}{html.escape(name)}{cl}{null}</td>'
                        f'<td align="left"><font color="#6B7280">{html.escape(c["type"])}</font></td></tr>')
    return f'"{t}" [label=<<table border="0" cellborder="1" cellspacing="0" cellpadding="3">{"".join(rows)}</table>>];'


def dot(domains, compact=False, rankdir="TB"):
    """domains: list of (label, tables, color). FK targets outside them are drawn as grey stubs."""
    out = ['digraph ERD {', f'graph [rankdir={rankdir}, newrank=true, splines=true, nodesep=0.4, ranksep=0.8, fontname="Helvetica", fontsize=18, pad=0.3];',
           'node [shape=plain, fontname="Helvetica", fontsize=10];', 'edge [color="#6B7280", arrowhead=crow, arrowtail=tee, dir=both, penwidth=1.0];']
    inside = set()
    for i, (label, tables, color) in enumerate(domains):
        out.append(f'subgraph cluster_{i} {{ label="{label}"; style="rounded,filled"; fillcolor="{color}"; color="#9CA3AF";')
        for t in tables:
            out.append(node(t, compact)); inside.add(t)
        out.append("}")
    stubs, seen = set(), set()
    for (t, c), f in sorted(fk.items()):
        if t not in inside or c in WHO:
            continue
        r = f["ref_table"]
        if r not in inside:
            stubs.add(r)
        if (t, r, c) in seen:
            continue
        seen.add((t, r, c))
        lbl = f' [label="{c}", fontsize=8, fontcolor="#6B7280"]' if not compact else ""
        rp = f':"{f["ref_column"]}"' if r in inside else ""
        out.append(f'"{r}"{rp} -> "{t}":"{c}"{lbl};')
    for r in sorted(stubs):
        out.append(node(r, stub=True))
    out.append("}")
    return "\n".join(out)


def render(name, text):
    with open(f"{OUT}/{name}.dot", "w") as fh:
        fh.write(text)
    for fmt in ("svg", "png"):
        subprocess.run(["dot", f"-T{fmt}", "-Gdpi=120", f"{OUT}/{name}.dot", "-o", f"{OUT}/{name}.{fmt}"], check=True)


EN = {k: k.split(" · ")[0] for k in DOMAINS}
render("erd_wave1_overview", dot([(EN[k], t, c) for k, (t, c) in DOMAINS.items()], compact=True))
for i, (k, (t, c)) in enumerate(DOMAINS.items(), 1):
    slug = EN[k].split(" ")[0].lower().replace("&", "")
    render(f"erd_{i:02d}_{slug}", dot([(EN[k], t, c)], compact=False, rankdir="LR"))

# ---------------- DBML (dbdiagram.io) ----------------
dbml = ["// Solaris Platform — Wave 1 schema (AUTH · I18N · ORG · ADMIN · COM) — MySQL 8", "// Generated from the live MySQL schema built by the Laravel migrations.", "", "Project solaris { database_type: 'MySQL' }", ""]
for label, (tables, color) in DOMAINS.items():
    dbml.append(f"TableGroup \"{label.split(' · ')[0]}\" {{")
    dbml += [f"  {t}" for t in tables]
    dbml.append("}\n")
for t in sorted(by_table):
    if t in INFRA:
        continue
    note = (tcom.get(t) or "").replace("'", "\\'")
    dbml.append(f"Table {t} [headercolor: #1F2937, note: '{note}'] {{")
    for c in by_table[t]:
        attrs = []
        if (t, c["column_name"]) in pk:
            attrs.append("pk")
        if not c["nullable"]:
            attrs.append("not null")
        if c["comment"]:
            attrs.append("note: '" + c["comment"].replace("'", "\\'") + "'")
        typ = c["type"].replace(" (virtual)", "")
        typ = f'"{typ}"' if (" " in typ) else typ
        dbml.append(f"  {c['column_name']} {typ}" + (f" [{', '.join(attrs)}]" if attrs else ""))
    dbml.append("}\n")
act = {"c": "cascade", "n": "set null", "r": "restrict", "a": "no action"}
for (t, c), f in sorted(fk.items()):
    if t in INFRA:
        continue
    dbml.append(f"Ref: {t}.{c} > {f['ref_table']}.{f['ref_column']} [delete: {act.get(f['on_delete'], 'no action')}]")
with open(f"{OUT}/schema_wave1.dbml", "w") as fh:
    fh.write("\n".join(dbml) + "\n")

# ---------------- data dictionary json ----------------
dd = {"tables": {t: {"comment": tcom.get(t), "columns": by_table[t]} for t in by_table if t not in INFRA},
      "fks": fks, "unique": uniq, "checks": checks, "triggers": triggers}
with open(f"{OUT}/data_dictionary.json", "w") as fh:
    json.dump(dd, fh, ensure_ascii=False, indent=1)
print("tables:", len([t for t in by_table if t not in INFRA]), "columns:", sum(len(v) for k, v in by_table.items() if k not in INFRA),
      "fks:", len(fks), "unique idx:", len(uniq), "checks:", len(checks), "triggers:", len(triggers))
