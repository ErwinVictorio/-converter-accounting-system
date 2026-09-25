import test from "node:test";
import assert from "node:assert/strict";
import { countNonWhitespace, masterDataText } from "../../resources/js/lib/masterDataText.js";
import { customerSchema, supplierSchema } from "../../resources/js/lib/FormSchema.js";

test("Unicode whitespace and characters match backend examples", () => {
    for (const value of ["A B", "A\t\nB", "A\u00a0B", "A\u0085B", "A\u3000B", "É😀", "A-"]) {
        assert.equal(countNonWhitespace(value), 2);
        assert.equal(masterDataText("Name", 2, 100).parse(value), value);
        assert.equal(masterDataText("Name", 2, 100).safeParse(value + "Z").success, false);
    }
});

test("both schemas preserve spaces, accept boundaries, and reject excess and blank text", () => {
    for (const schema of [customerSchema, supplierSchema]) {
        const data = { tin: "123-456-789-000", name: Array(50).fill("A").join(" "), addr: Array(30).fill("B").join(" "), city: Array(30).fill("C").join(" ") };
        assert.deepEqual(schema.parse(data), data);
        for (const field of ["name", "addr", "city"]) {
            assert.equal(schema.safeParse({ ...data, [field]: data[field] + "Z" }).success, false);
            assert.equal(schema.safeParse({ ...data, [field]: " \t\n\u00a0\u2003" }).success, false);
        }
        assert.equal(schema.safeParse({ ...data, name: "A" + " ".repeat(300) }).success, false);
    }
});
