"use client";

import {
  Area,
  AreaChart,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
  PieChart,
  Pie,
  Cell,
  Legend,
} from "recharts";

export function TrendChart({
  data,
}: {
  data: { date: string; count: number }[];
}) {
  return (
    <div className="h-64 w-full px-2 pb-2">
      <ResponsiveContainer width="100%" height="100%">
        <AreaChart data={data} margin={{ top: 16, right: 12, left: -16, bottom: 0 }}>
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor="#6366f1" stopOpacity={0.35} />
              <stop offset="100%" stopColor="#6366f1" stopOpacity={0} />
            </linearGradient>
          </defs>
          <XAxis
            dataKey="date"
            tick={{ fontSize: 11, fill: "var(--muted)" }}
            tickLine={false}
            axisLine={false}
          />
          <YAxis
            allowDecimals={false}
            tick={{ fontSize: 11, fill: "var(--muted)" }}
            tickLine={false}
            axisLine={false}
            width={32}
          />
          <Tooltip
            contentStyle={{
              background: "var(--surface)",
              border: "1px solid var(--border)",
              borderRadius: 10,
              fontSize: 12,
              color: "var(--fg)",
            }}
            labelStyle={{ color: "var(--fg)" }}
          />
          <Area
            type="monotone"
            dataKey="count"
            name="Tickets"
            stroke="#6366f1"
            strokeWidth={2}
            fill="url(#g)"
          />
        </AreaChart>
      </ResponsiveContainer>
    </div>
  );
}

export function StatusPie({
  data,
}: {
  data: { name: string; value: number; color: string }[];
}) {
  const total = data.reduce((s, d) => s + d.value, 0);
  return (
    <div className="h-64 w-full">
      <ResponsiveContainer width="100%" height="100%">
        <PieChart>
          <Pie
            data={data}
            dataKey="value"
            nameKey="name"
            innerRadius={55}
            outerRadius={85}
            paddingAngle={2}
            strokeWidth={0}
          >
            {data.map((d) => (
              <Cell key={d.name} fill={d.color} />
            ))}
          </Pie>
          <Tooltip
            contentStyle={{
              background: "var(--surface)",
              border: "1px solid var(--border)",
              borderRadius: 10,
              fontSize: 12,
              color: "var(--fg)",
            }}
          />
          <Legend
            verticalAlign="bottom"
            iconType="circle"
            formatter={(v) => <span style={{ color: "var(--muted)", fontSize: 12 }}>{v}</span>}
          />
          <text
            x="50%"
            y="44%"
            textAnchor="middle"
            dominantBaseline="middle"
            style={{ fill: "var(--fg)", fontSize: 24, fontWeight: 700 }}
          >
            {total}
          </text>
        </PieChart>
      </ResponsiveContainer>
    </div>
  );
}
