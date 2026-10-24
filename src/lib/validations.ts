import { z } from "zod";

export const loginSchema = z.object({
  email: z.string().email("Invalid email address"),
  password: z.string().min(1, "Password is required"),
});

export const registerSchema = z.object({
  organizationName: z
    .string()
    .min(2, "Organization name must be at least 2 characters")
    .max(60),
  name: z.string().min(2, "Name must be at least 2 characters"),
  email: z.string().email("Invalid email address"),
  password: z.string().min(6, "Password must be at least 6 characters"),
});

// A tenant admin inviting / creating a user inside their own tenant.
export const createMemberSchema = z.object({
  name: z.string().min(2, "Name must be at least 2 characters"),
  email: z.string().email("Invalid email address"),
  password: z.string().min(6, "Password must be at least 6 characters"),
  role: z.enum(["ADMIN", "AGENT", "CUSTOMER"]),
});

export const createTicketSchema = z.object({
  subject: z.string().min(3, "Subject must be at least 3 characters").max(140),
  // Validated against the tenant's custom priorities at the action layer.
  priority: z.string().min(1, "Priority is required"),
  categoryId: z.string().optional().nullable(),
});

export const updateTicketSchema = z.object({
  // Status & priority are validated against the tenant's custom sets in the action.
  status: z.string().min(1).optional(),
  priority: z.string().min(1).optional(),
  assigneeId: z.string().nullable().optional(),
  categoryId: z.string().nullable().optional(),
});


export const updateUserRoleSchema = z.object({
  userId: z.string(),
  role: z.enum(["ADMIN", "AGENT", "CUSTOMER"]),
});

export type LoginInput = z.infer<typeof loginSchema>;
export type RegisterInput = z.infer<typeof registerSchema>;
export type CreateTicketInput = z.infer<typeof createTicketSchema>;
