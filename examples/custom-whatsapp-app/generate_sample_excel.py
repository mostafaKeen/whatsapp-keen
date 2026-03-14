import openpyxl

# Create a new workbook and select the active sheet
wb = openpyxl.Workbook()
ws = wb.active
ws.title = "WhatsApp Contacts"

# Define headers
headers = ["name", "company", "phone", "variable1", "variable2"]
ws.append(headers)

# Sample data
data = [
    ["Mostafa", "Keen", "201129274930", "Marketing", "10% Discount"],
    ["Faiz", "Bitrix", "201026060251", "Support", "Premium Ticket"],
    ["Test User", "Demo Corp", "1234567890", "Alpha", "Beta"]
]

for row in data:
    ws.append(row)

# Save the workbook
wb.save("test_contacts.xlsx")
print("Excel file 'test_contacts.xlsx' generated successfully.")
