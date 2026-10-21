export function createLeakFixture(store) {
    let next = 1;
    const pools = [],
        tasks = [],
        rounds = [],
        members = [],
        events = [];
    const now = () => Math.floor(Date.now() / 1000);
    const server = (pool) =>
        store.nodes.find(
            (node) => node.id === pool.node_id && node.type === pool.node_type,
        );
    const roster = (data) =>
        store.users.filter(
            (user) =>
                user.plan_id &&
                (data.scope === "plans"
                    ? data.plan_ids?.includes(user.plan_id)
                    : data.scope === "emails"
                      ? data.emails.split(/[\s,;]+/).includes(user.email)
                      : true),
        );
    const start = (task, people, data, parent) => {
        const a = pools.find((node) => node.id === data.a_node_id),
            b = pools.find((node) => node.id === data.b_node_id);
        const round = {
            id: next++,
            task_id: task.id,
            sequence: parent ? parent.sequence + 1 : 1,
            selected_from: data.branch,
            a_node_id: a.id,
            b_node_id: b.id,
            a_snapshot: server(a),
            b_snapshot: server(b),
            created_at: now(),
            result_a: "unknown",
            result_b: "unknown",
        };
        rounds.push(round);
        task.current_round_id = round.id;
        a.state = b.state = "active";
        people.forEach((person, index) =>
            members.push({
                id: next++,
                round_id: round.id,
                user_id: person.id ?? person.user_id,
                email: person.email,
                branch: index < Math.ceil(people.length / 2) ? "a" : "b",
            }),
        );
        return round;
    };
    return ({ path, data, query, method }) => {
        if (!path.startsWith("/leak-probe/")) return null;
        const op = path.split("/").at(-1);
        if (op === "nodes") {
            if (method === "POST") {
                if (data.action === "register")
                    pools.push({
                        id: next++,
                        node_type: data.node_type,
                        node_id: data.node_id,
                        state: "ready",
                        config_matches: true,
                    });
                else {
                    const index = pools.findIndex(
                        (node) => node.id === data.id,
                    );
                    if (index >= 0) pools.splice(index, 1);
                }
                return { data: true };
            }
            return {
                data: pools.map((node) => ({ ...node, server: server(node) })),
                history_days: 90,
                available: store.nodes.map((node) => ({
                    ...node,
                    node_type: node.type,
                    node_id: node.id,
                })),
            };
        }
        if (op === "preview") {
            const users = roster(data);
            return {
                data: {
                    count: users.length,
                    hash: "f".repeat(64),
                    sample: users.map((user) => user.email),
                },
            };
        }
        if (op === "create") {
            const task = {
                id: next++,
                name: data.name,
                status: "active",
                member_count: roster(data).length,
                observation_hours: data.observation_hours,
                created_at: now(),
            };
            tasks.push(task);
            start(task, roster(data), data);
            return { data: { id: task.id } };
        }
        if (op === "fetch")
            return { data: tasks.toReversed(), total: tasks.length };
        const task = tasks.find(
            (task) => task.id === Number(query.get("id") ?? data.id),
        );
        if (op === "detail")
            return {
                data: task,
                candidates:
                    task.status === "identified"
                        ? members.filter(
                              (member) =>
                                  member.round_id === task.current_round_id &&
                                  member.branch === task.final_branch,
                          )
                        : [],
                rounds: rounds
                    .filter((round) => round.task_id === task.id)
                    .map((round) => {
                        const people = members.filter(
                            (member) => member.round_id === round.id,
                        );
                        return {
                            ...round,
                            a_count: people.filter(
                                (member) => member.branch === "a",
                            ).length,
                            b_count: people.filter(
                                (member) => member.branch === "b",
                            ).length,
                            a_seen: 0,
                            b_seen: 0,
                            a_pull: false,
                            b_pull: false,
                        };
                    }),
                events: events.filter((event) => event.task_id === task.id),
            };
        if (op === "members") {
            const rows = members.filter(
                (member) =>
                    member.round_id === Number(query.get("round_id")) &&
                    (!query.get("branch") ||
                        member.branch === query.get("branch")) &&
                    (!query.get("email") ||
                        member.email.includes(query.get("email"))),
            );
            return { data: rows, total: rows.length };
        }
        if (op === "update") {
            const round = rounds.find(
                (round) => round.id === task.current_round_id,
            );
            if (data.action === "results")
                Object.assign(round, {
                    result_a: data.result_a,
                    result_b: data.result_b,
                    note: data.note,
                });
            if (data.action === "pause") task.status = "paused";
            if (data.action === "resume") task.status = "active";
            if (data.action === "finish" || data.action === "advance") {
                round.closed_at = now();
                pools
                    .filter((node) =>
                        [round.a_node_id, round.b_node_id].includes(node.id),
                    )
                    .forEach((node) => (node.state = "ready"));
                if (data.action === "finish") task.status = "completed";
                else {
                    const candidates = members.filter(
                        (member) =>
                            member.round_id === round.id &&
                            member.branch === data.branch,
                    );
                    if (candidates.length === 1) {
                        task.status = "identified";
                        task.final_branch = data.branch;
                    } else
                        start(
                            task,
                            candidates.map((member) => ({
                                ...member,
                                id: member.user_id,
                            })),
                            data,
                            round,
                        );
                }
            }
            events.push({
                task_id: task.id,
                action: data.action,
                note: data.note,
                created_at: now(),
                actor_email: "admin@example.test",
            });
            return { data: { id: task.id } };
        }
        return { data: true };
    };
}
